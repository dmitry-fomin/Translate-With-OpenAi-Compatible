<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\CoverTranslationOverrides;
use App\Application\TranslateBookUseCase;
use App\Domain\Book;
use App\Domain\BookMetadataTranslation;
use App\Domain\BookMetadataTranslatorInterface;
use App\Domain\Chapter;
use App\Domain\CoverImage;
use App\Domain\CoverTranslationBrief;
use App\Domain\CoverTranslatorInterface;
use App\Domain\EpubRepositoryInterface;
use App\Domain\SummarizerInterface;
use App\Domain\SummaryCacheInterface;
use App\Domain\TranslationCacheInterface;
use App\Domain\TranslatorInterface;
use PHPUnit\Framework\TestCase;

class TranslateBookUseCaseTest extends TestCase
{
    public function testExecute(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $cache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);

        $chapter1 = new Chapter('c1', 'Chapter 1', 'Content 1', 'c1.xhtml', '/abs/c1.xhtml');
        $chapter2 = new Chapter('c2', 'Chapter 2', 'Content 2', 'c2.xhtml', '/abs/c2.xhtml');
        $book = new Book('Test Book', [$chapter1, $chapter2]);

        $repo->expects($this->once())->method('load')->willReturn($book);

        // Умный кэш
        $cacheData = [];
        $cache->method('set')->willReturnCallback(function ($bookId, $chapterId, $summary) use (&$cacheData) {
            $cacheData[$chapterId] = $summary;
        });
        $cache->method('get')->willReturnCallback(function ($bookId, $chapterId) use (&$cacheData) {
            return $cacheData[$chapterId] ?? null;
        });

        $translator->expects($this->exactly(2))
            ->method('translate')
            ->willReturnCallback(function ($content, $summary) {
                if ($content === 'Content 1' && $summary === '') {
                    return 'Translated 1';
                }
                if ($content === 'Content 2' && $summary === 'Summary 1') {
                    return 'Translated 2';
                }
                return '';
            });

        $summarizer->expects($this->exactly(2))
            ->method('summarize')
            ->willReturnCallback(function ($content, $summary) {
                if ($content === 'Content 1' && $summary === '') {
                    return 'Summary 1';
                }
                if ($content === 'Content 2' && $summary === 'Summary 1') {
                    return 'Summary 2';
                }
                return '';
            });

        $repo->expects($this->once())->method('save')->with($book, 'output');

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $cache, $translationCache);
        $useCase->execute('input', 'output');

        $this->assertEquals('Translated 1', $chapter1->getTranslatedContent());
        $this->assertEquals('Translated 2', $chapter2->getTranslatedContent());
    }

    public function testExecuteWithCoverTranslation(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $cache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);
        $coverTranslator = $this->createMock(CoverTranslatorInterface::class);

        $cover = new CoverImage('original-bytes', 'image/jpeg', 'OEBPS/images/cover.jpg', '/abs/cover.jpg');
        $book = new Book('Test Book', [], 'John Doe');
        $book->setCover($cover);

        $repo->expects($this->once())->method('load')->willReturn($book);
        $repo->expects($this->once())->method('save')->with($book, 'output');

        $overrides = new CoverTranslationOverrides(
            translatedTitle: 'Тестовая книга',
            translatedAuthor: 'Джон Доу',
            series: 'Книга первая',
        );

        $expectedBrief = new CoverTranslationBrief(
            originalTitle: 'Test Book',
            translatedTitle: 'Тестовая книга',
            authorOriginal: 'John Doe',
            authorTranslated: 'Джон Доу',
            series: 'Книга первая',
        );

        $coverTranslator->expects($this->once())
            ->method('translate')
            ->with($cover, $expectedBrief)
            ->willReturn('translated-image-bytes');

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $cache, $translationCache, $coverTranslator);
        $useCase->execute('input', 'output', null, $overrides);

        $this->assertSame('translated-image-bytes', $cover->getTranslatedBytes());
    }

    public function testCoverBriefIsAutoBuiltViaMetadataTranslatorWhenOverridesEmpty(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $cache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);
        $coverTranslator = $this->createMock(CoverTranslatorInterface::class);
        $metadataTranslator = $this->createMock(BookMetadataTranslatorInterface::class);

        $cover = new CoverImage('orig', 'image/jpeg', 'OEBPS/cover.jpg', '/abs/cover.jpg');
        $book = new Book('Empire of the Dawn', [], 'Jay Kristoff');
        $book->setCover($cover);

        $repo->expects($this->once())->method('load')->willReturn($book);
        $repo->expects($this->once())->method('save');

        $metadataTranslator->expects($this->once())
            ->method('translate')
            ->with('Empire of the Dawn', 'Jay Kristoff')
            ->willReturn(new BookMetadataTranslation('Империя рассвета', 'Джей Кристофф'));

        $coverTranslator->expects($this->once())
            ->method('translate')
            ->with(
                $cover,
                $this->callback(function (CoverTranslationBrief $brief): bool {
                    return $brief->originalTitle === 'Empire of the Dawn'
                        && $brief->translatedTitle === 'Империя рассвета'
                        && $brief->authorOriginal === 'Jay Kristoff'
                        && $brief->authorTranslated === 'Джей Кристофф'
                        && $brief->series === null;
                }),
            )
            ->willReturn('bytes');

        $overrides = new CoverTranslationOverrides();
        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $cache, $translationCache, $coverTranslator, $metadataTranslator);
        $useCase->execute('input', 'output', null, $overrides);
    }

    public function testCoverBriefAutoBuildFailsWithoutMetadataTranslator(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $cache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);
        $coverTranslator = $this->createMock(CoverTranslatorInterface::class);

        $cover = new CoverImage('orig', 'image/jpeg', 'OEBPS/cover.jpg', '/abs/cover.jpg');
        $book = new Book('Empire of the Dawn', [], 'Jay Kristoff');
        $book->setCover($cover);

        $repo->expects($this->once())->method('load')->willReturn($book);
        $repo->expects($this->never())->method('save');

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $cache, $translationCache, $coverTranslator, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BookMetadataTranslator is not configured/');
        $useCase->execute('input', 'output', null, new CoverTranslationOverrides());
    }

    public function testCoverTranslationWithoutCoverThrows(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $cache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);
        $coverTranslator = $this->createMock(CoverTranslatorInterface::class);

        $book = new Book('Test Book', []);
        $repo->expects($this->once())->method('load')->willReturn($book);
        $repo->expects($this->never())->method('save');

        $overrides = new CoverTranslationOverrides(
            translatedTitle: 'Тестовая книга',
        );

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $cache, $translationCache, $coverTranslator);

        $this->expectException(\RuntimeException::class);
        $useCase->execute('input', 'output', null, $overrides);
    }

    public function testExecuteSingleChapterWithCache(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $cache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);

        $chapter1 = new Chapter('c1', 'Chapter 1', 'Content 1', 'c1.xhtml', '/abs/c1.xhtml');
        $chapter2 = new Chapter('c2', 'Chapter 2', 'Content 2', 'c2.xhtml', '/abs/c2.xhtml');
        $book = new Book('Test Book', [$chapter1, $chapter2]);

        $repo->expects($this->once())->method('load')->willReturn($book);

        // Имитируем, что саммари первой главы уже есть в кеше
        $cache->method('get')->willReturnCallback(function ($bookId, $chapterId) {
            if ($chapterId === 'c1') {
                return 'Cached Summary 1';
            }
            return null;
        });

        // Ожидаем перевод ТОЛЬКО для главы 2
        $translator->expects($this->once())
            ->method('translate')
            ->with('Content 2', 'Cached Summary 1')
            ->willReturn('Translated 2');

        // Ожидаем саммари ТОЛЬКО для главы 2
        $summarizer->expects($this->once())
            ->method('summarize')
            ->with('Content 2', 'Cached Summary 1')
            ->willReturn('Summary 2');

        $repo->expects($this->once())->method('save');

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $cache, $translationCache);
        $useCase->execute('input', 'output', 'c2.xhtml');

        $this->assertNull($chapter1->getTranslatedContent());
        $this->assertEquals('Translated 2', $chapter2->getTranslatedContent());
    }

    public function testTranslationCacheHitSkipsTranslator(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $summaryCache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);

        $chapter1 = new Chapter('c1', 'Chapter 1', 'Content 1', 'OEBPS/c1.xhtml', '/abs/c1.xhtml');
        $book = new Book('Test Book', [$chapter1]);

        $repo->expects($this->once())->method('load')->willReturn($book);

        $translationCache->expects($this->once())
            ->method('get')
            ->with($this->anything(), 'OEBPS/c1.xhtml')
            ->willReturn('Cached translation');

        $translationCache->expects($this->never())->method('set');
        $translator->expects($this->never())->method('translate');
        // Cache hit ⇒ summarizer тоже не дёргается, иначе перезапуск после краша всё равно
        // упирался бы в LLM на каждой закешированной главе.
        $summarizer->expects($this->never())->method('summarize');

        $summaryCache->method('get')->willReturn(null);

        $repo->expects($this->once())->method('save');

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $summaryCache, $translationCache);
        $useCase->execute('input', 'output');

        $this->assertSame('Cached translation', $chapter1->getTranslatedContent());
    }

    public function testProgressCallbackReportsCacheHitAndMissFlag(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $summaryCache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);

        $chapter1 = new Chapter('c1', 'Chapter 1', 'Content 1', 'OEBPS/c1.xhtml', '/abs/c1.xhtml');
        $chapter2 = new Chapter('c2', 'Chapter 2', 'Content 2', 'OEBPS/c2.xhtml', '/abs/c2.xhtml');
        $book = new Book('Test Book', [$chapter1, $chapter2]);

        $repo->method('load')->willReturn($book);
        $repo->method('save');

        $translationCache->method('get')->willReturnCallback(function ($_, $relPath) {
            return $relPath === 'OEBPS/c1.xhtml' ? 'Cached translation' : null;
        });
        $translator->method('translate')->willReturn('Fresh translation');
        $summaryCache->method('get')->willReturn(null);
        $summarizer->method('summarize')->willReturn('Summary');

        $events = [];
        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $summaryCache, $translationCache);
        $useCase->execute('input', 'output', null, null, function ($current, $total, $chapterId, $chapterTitle, $cached) use (&$events) {
            $events[] = [$current, $total, $chapterId, $chapterTitle, $cached];
        });

        $this->assertSame(
            [
                [1, 2, 'c1', 'Chapter 1', true],
                [2, 2, 'c2', 'Chapter 2', false],
            ],
            $events,
        );
    }

    public function testTranslationCacheMissCallsTranslatorAndStoresResult(): void
    {
        $repo = $this->createMock(EpubRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $summarizer = $this->createMock(SummarizerInterface::class);
        $summaryCache = $this->createMock(SummaryCacheInterface::class);
        $translationCache = $this->createMock(TranslationCacheInterface::class);

        $chapter1 = new Chapter('c1', 'Chapter 1', 'Content 1', 'OEBPS/c1.xhtml', '/abs/c1.xhtml');
        $book = new Book('Test Book', [$chapter1]);

        $repo->expects($this->once())->method('load')->willReturn($book);

        $translationCache->expects($this->once())
            ->method('get')
            ->with($this->anything(), 'OEBPS/c1.xhtml')
            ->willReturn(null);

        $translator->expects($this->once())
            ->method('translate')
            ->with('Content 1', '')
            ->willReturn('Fresh translation');

        $translationCache->expects($this->once())
            ->method('set')
            ->with($this->anything(), 'OEBPS/c1.xhtml', 'Fresh translation');

        $summaryCache->method('get')->willReturn(null);
        $summarizer->method('summarize')->willReturn('Summary 1');

        $repo->expects($this->once())->method('save');

        $useCase = new TranslateBookUseCase($repo, $translator, $summarizer, $summaryCache, $translationCache);
        $useCase->execute('input', 'output');

        $this->assertSame('Fresh translation', $chapter1->getTranslatedContent());
    }
}
