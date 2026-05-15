<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Book;
use App\Domain\BookMetadataTranslatorInterface;
use App\Domain\CoverTranslationBrief;
use App\Domain\CoverTranslatorInterface;
use App\Domain\EpubRepositoryInterface;
use App\Domain\SummarizerInterface;
use App\Domain\SummaryCacheInterface;
use App\Domain\TranslationCacheInterface;
use App\Domain\TranslationScope;
use App\Domain\TranslatorInterface;

class TranslateBookUseCase
{
    public function __construct(
        private readonly EpubRepositoryInterface $epubRepository,
        private readonly TranslatorInterface $translator,
        private readonly SummarizerInterface $summarizer,
        private readonly SummaryCacheInterface $summaryCache,
        private readonly TranslationCacheInterface $translationCache,
        private readonly ?CoverTranslatorInterface $coverTranslator = null,
        private readonly ?BookMetadataTranslatorInterface $metadataTranslator = null,
    ) {
    }

    /**
     * @param string $inputPath
     * @param string $outputPath
     * @param string|null $targetChapterPath Относительный путь к главе для одиночного перевода
     * @param CoverTranslationOverrides|null $coverOverrides Если задан — перевести обложку.
     *        Пустые поля автоматически дозаполняются через BookMetadataTranslator.
     * @param (callable(int $current, int $total, string $chapterId, string $chapterTitle, bool $cached): void)|null $onProgress
     */
    public function execute(string $inputPath, string $outputPath, ?string $targetChapterPath = null, ?CoverTranslationOverrides $coverOverrides = null, ?callable $onProgress = null): void
    {

        $book = $this->epubRepository->load($inputPath);
        $chapters = $book->getChapters();
        $bookId = realpath($inputPath) ?: $inputPath;

        $previousChapterId = null;

        foreach ($chapters as $index => $chapter) {
            if ($targetChapterPath !== null && $chapter->getRelativeFilePath() !== $targetChapterPath) {
                $previousChapterId = $chapter->getId();
                continue;
            }

            $cachedTranslation = $this->translationCache->get($bookId, $chapter->getRelativeFilePath());
            $cacheHit = $cachedTranslation !== null;

            if ($onProgress) {
                $onProgress($index + 1, count($chapters), $chapter->getId(), $chapter->getTitle(), $cacheHit);
            }

            if ($cacheHit) {
                // Cache hit: пропускаем LLM-вызовы полностью. Summary не считаем — он нужен только как
                // контекст следующему translate(); если следующая глава тоже из кеша, summary вхолостую.
                // Если следующая будет cache miss — её translate получит summary предыдущей из кеша
                // (т.к. перевод и summary пишутся последовательно, наличие одного ⇒ наличие второго).
                $chapter->setTranslatedContent($cachedTranslation);
                $previousChapterId = $chapter->getId();
                continue;
            }

            $previousSummary = $previousChapterId ? ($this->summaryCache->get($bookId, $previousChapterId) ?? '') : '';
            $scope = new TranslationScope($bookId, $chapter->getRelativeFilePath());
            $translated = $this->translator->translate($chapter->getContent(), $previousSummary, $scope);
            $this->translationCache->set($bookId, $chapter->getRelativeFilePath(), $translated);
            $chapter->setTranslatedContent($translated);

            $summaryForThisChapter = $this->summaryCache->get($bookId, $chapter->getId());
            if ($summaryForThisChapter === null) {
                $summaryForThisChapter = $this->summarizer->summarize($chapter->getContent(), $previousSummary);
                $this->summaryCache->set($bookId, $chapter->getId(), $summaryForThisChapter);
            }

            $previousChapterId = $chapter->getId();
        }

        if ($coverOverrides !== null) {
            $cover = $book->getCover();
            if ($cover === null) {
                throw new \RuntimeException('Cover translation requested, but no cover image was found in the EPUB.');
            }
            if ($this->coverTranslator === null) {
                throw new \RuntimeException('Cover translation requested, but CoverTranslator is not configured.');
            }
            $brief = $this->buildCoverBrief($book, $coverOverrides);
            $translatedBytes = $this->coverTranslator->translate($cover, $brief);
            $cover->setTranslatedBytes($translatedBytes);
        }

        $this->epubRepository->save($book, $outputPath);
    }

    private function buildCoverBrief(Book $book, CoverTranslationOverrides $o): CoverTranslationBrief
    {
        $originalTitle = $o->originalTitle ?? $book->getTitle();
        $originalAuthor = $o->originalAuthor ?? $book->getAuthor();

        $translatedTitle = $o->translatedTitle;
        $translatedAuthor = $o->translatedAuthor;

        if ($translatedTitle === null || ($translatedAuthor === null && $originalAuthor !== null)) {
            if ($this->metadataTranslator === null) {
                throw new \RuntimeException('Cover title/author auto-generation requested, but BookMetadataTranslator is not configured.');
            }
            $auto = $this->metadataTranslator->translate($originalTitle, $originalAuthor);
            $translatedTitle ??= $auto->title;
            $translatedAuthor ??= $auto->author;
        }

        return new CoverTranslationBrief(
            originalTitle: $originalTitle,
            translatedTitle: $translatedTitle,
            authorOriginal: $originalAuthor,
            authorTranslated: $translatedAuthor,
            series: $o->series,
        );
    }
}
