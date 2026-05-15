<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\EpubRepositoryInterface;
use App\Infrastructure\LLM\LlmUsageTracker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class TranslateCommand extends Command
{
    private const BAR_WIDTH = 42;

    public function __construct(
        private readonly TranslateBookUseCase $useCase,
        private readonly EpubRepositoryInterface $epubRepository,
        private readonly string $targetLanguage,
        private readonly string $cacheDir,
        private readonly LlmUsageTracker $usageTracker,
        private readonly string $modelName,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('translate')
            ->setDescription('Translate an EPUB book using an OpenAI-compatible LLM')
            ->addArgument('input', InputArgument::REQUIRED, 'Path to the input EPUB file/folder')
            ->addOption('chapter', 'c', InputOption::VALUE_REQUIRED, 'Specific chapter file to translate (e.g. OEBPS/xhtml/chapter1.xhtml)')
            ->addOption('cover', null, InputOption::VALUE_NONE, 'Russify the book cover (auto-translates title/author if flags omitted)')
            ->addOption('cover-title', null, InputOption::VALUE_REQUIRED, 'Translated book title to put on the cover (verbatim). If omitted — auto-generated via LLM.')
            ->addOption('cover-title-original', null, InputOption::VALUE_REQUIRED, 'Original book title as it appears on the cover (defaults to EPUB metadata title)')
            ->addOption('cover-author', null, InputOption::VALUE_REQUIRED, 'Translated author name (verbatim). If omitted — auto-transliterated via LLM.')
            ->addOption('cover-author-original', null, InputOption::VALUE_REQUIRED, 'Original author name as it appears on the cover (defaults to EPUB dc:creator)')
            ->addOption('cover-series', null, InputOption::VALUE_REQUIRED, 'Series mark to print on the cover (e.g. "Книга третья")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = (string) $input->getArgument('input');
        $chapterPath = $input->getOption('chapter');
        $translateCover = (bool) $input->getOption('cover');

        $coverOverrides = null;
        if ($translateCover) {
            $coverOverrides = new CoverTranslationOverrides(
                translatedTitle: $input->getOption('cover-title') ?: null,
                originalTitle: $input->getOption('cover-title-original') ?: null,
                translatedAuthor: $input->getOption('cover-author') ?: null,
                originalAuthor: $input->getOption('cover-author-original') ?: null,
                series: $input->getOption('cover-series') ?: null,
            );
        }

        $workDir = $this->resolveWorkDir($inputPath);
        $packedPath = $this->resolvePackedPath($inputPath);

        $panel = ($output instanceof ConsoleOutputInterface) ? $output->section() : null;
        $this->usageTracker->reset();

        $startedAt = microtime(true);
        $state = new class () {
            public string $bookName = '';
            public int $processed = 0;
            public int $total = 0;
            public string $chapterId = '';
            public string $chapterTitle = '';
            public bool $cached = false;
            public int $cacheHits = 0;
            public bool $allDone = false;
            public bool $coverPhase = false;
        };
        $state->bookName = basename($inputPath);

        $render = function () use ($panel, $state, $startedAt): void {
            if ($panel === null) {
                return;
            }
            $this->renderPanel($panel, $state, $startedAt);
        };

        $this->usageTracker->setOnRecord(function () use ($render, $state): void {
            // Если все главы пройдены и LLM-запрос всё ещё прилетает — это обложка.
            if ($state->total > 0 && $state->processed >= $state->total) {
                $state->coverPhase = true;
            }
            $render();
        });

        $render();

        try {
            $this->useCase->execute($inputPath, $workDir, $chapterPath, $coverOverrides, function ($current, $total, $chapterId, $chapterTitle, $cached) use ($state, $render): void {
                $state->total = $total;
                $state->processed = $current;
                $state->chapterId = $chapterId;
                $state->chapterTitle = $chapterTitle;
                $state->cached = $cached;
                if ($cached) {
                    ++$state->cacheHits;
                }
                $render();
            });

            $state->allDone = true;
            $state->coverPhase = false;
            $this->usageTracker->setOnRecord(null);
            $render();

            $this->epubRepository->pack($workDir, $packedPath);

            $output->writeln('');
            $output->writeln(sprintf(' <fg=green;options=bold>✓ Готово!</> Книга сохранена в <fg=cyan>%s</>', $packedPath));
            $output->writeln('');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->usageTracker->setOnRecord(null);
            $output->writeln('');
            $output->writeln('<error>Ошибка: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function renderPanel(ConsoleSectionOutput $panel, object $state, float $startedAt): void
    {
        $tracker = $this->usageTracker;

        $total = max(0, $state->total);
        $processed = max(0, $state->processed);
        $pct = $total > 0 ? min(100.0, $processed / $total * 100.0) : 0.0;

        $elapsedSec = max(0, (int) (microtime(true) - $startedAt));
        $etaSec = null;
        if ($processed > 0 && $total > 0 && !$state->allDone) {
            $perChapter = (microtime(true) - $startedAt) / $processed;
            $etaSec = (int) ($perChapter * max(0, $total - $processed));
        }

        $bar = $this->renderBar($processed, $total, self::BAR_WIDTH);

        $statusLine = match (true) {
            $state->allDone => '<fg=green;options=bold>✓ перевод завершён</>',
            $state->coverPhase => '<fg=magenta>► переводим обложку…</>',
            $state->cached => '<fg=cyan>✓ глава из кеша</>',
            $processed === 0 => '<fg=gray>► подготовка…</>',
            default => '<fg=yellow;options=bold>► переводим главу через LLM…</>',
        };

        $chapterLabel = match (true) {
            $state->chapterId !== '' && $state->chapterTitle !== '' && $state->chapterTitle !== $state->chapterId
                => $state->chapterId . ': ' . $state->chapterTitle,
            $state->chapterId !== '' => $state->chapterId,
            default => '—',
        };

        $lines = [
            '',
            ' <fg=cyan;options=bold>📖 EPUB Translator</> <fg=gray>→</> <fg=green;options=bold>' . $this->targetLanguage . '</>',
            ' <fg=gray>Источник:</> ' . $state->bookName,
            ' <fg=gray>Модель:</>  <fg=magenta>' . $this->modelName . '</>',
            ' <fg=gray>' . str_repeat('─', 62) . '</>',
            sprintf(
                ' <fg=white;options=bold>Глава %d / %d</>  <fg=gray>(%5.1f%%)</>   <fg=cyan>%s</>',
                $processed,
                $total,
                $pct,
                $chapterLabel,
            ),
            ' <fg=blue>[' . $bar . ']</>   ' . $statusLine,
            '',
            sprintf(
                ' <fg=gray>⏱  прошло</>  <fg=white>%s</>     <fg=gray>ETA</>  <fg=white>%s</>',
                $this->formatDuration($elapsedSec),
                $etaSec === null ? '—' : '~' . $this->formatDuration($etaSec),
            ),
            sprintf(
                ' <fg=gray>🔁 LLM запросов:</> <fg=white;options=bold>%d</>     <fg=gray>кеш-хитов:</> <fg=white;options=bold>%d</>',
                $tracker->getRequestCount(),
                $state->cacheHits,
            ),
            sprintf(
                ' <fg=magenta>↑ отправлено</>  <fg=white;options=bold>%9s</> ток.   <fg=gray>(посл. %s)</>',
                $this->fmtNumber($tracker->getTotalInputTokens()),
                $this->fmtNumber($tracker->getLastInputTokens()),
            ),
            sprintf(
                ' <fg=green>↓ получено  </>  <fg=white;options=bold>%9s</> ток.   <fg=gray>(посл. %s)</>',
                $this->fmtNumber($tracker->getTotalOutputTokens()),
                $this->fmtNumber($tracker->getLastOutputTokens()),
            ),
            '',
        ];

        // ConsoleSectionOutput::overwrite() в decorated-режиме пишет содержимое
        // в обход OutputFormatter (после первого вызова, когда секция уже непустая),
        // из-за чего теги типа <fg=cyan> отображаются как литералы.
        // Поэтому форматируем строки вручную.
        $formatter = $panel->getFormatter();
        $formatted = array_map(static fn (string $line): string => $formatter->format($line) ?? '', $lines);

        $panel->overwrite($formatted);
    }

    private function renderBar(int $current, int $total, int $width): string
    {
        if ($total <= 0) {
            return str_repeat('░', $width);
        }
        $filled = (int) round($width * min(1.0, $current / $total));
        $filled = max(0, min($width, $filled));
        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    }

    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    private function fmtNumber(int $n): string
    {
        // Тонкий пробел в качестве разделителя тысяч — читабельно и узко.
        return number_format($n, 0, '.', "\u{202F}");
    }

    private function resolveWorkDir(string $inputPath): string
    {
        $key = sha1((string) (realpath($inputPath) ?: $inputPath));
        return rtrim($this->cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'translated-' . $key;
    }

    private function resolvePackedPath(string $inputPath): string
    {
        $baseName = pathinfo($inputPath, PATHINFO_FILENAME);
        if ($baseName === '') {
            $baseName = 'book';
        }
        $cwd = getcwd() ?: '.';
        return $cwd . DIRECTORY_SEPARATOR . $baseName . '.' . $this->targetLanguage . '.epub';
    }
}
