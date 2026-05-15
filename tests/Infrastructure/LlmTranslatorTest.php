<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Domain\TranslationScope;
use App\Infrastructure\FileTranslationChunkCache;
use App\Infrastructure\LLM\LlmClientInterface;
use App\Infrastructure\LlmTranslator;
use App\Infrastructure\TextSplitter;
use PHPUnit\Framework\TestCase;

class LlmTranslatorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
        $this->tempFiles = [];
    }

    private function tempCacheFile(): string
    {
        $path = sys_get_temp_dir() . '/llm_translator_chunk_cache_' . uniqid() . '.json';
        $this->tempFiles[] = $path;
        return $path;
    }


    public function testTranslateUsesClientWithLanguagePrompt(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator(
            $client,
            'French',
            'Translate to {language}:',
        );

        $client->expects($this->once())
            ->method('request')
            ->with('Translate to French:', 'Hello')
            ->willReturn('Bonjour');

        $this->assertSame('Bonjour', $translator->translate('Hello'));
    }

    public function testTranslateAddsContext(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator($client, 'Russian', 'Translate:');

        $client->expects($this->once())
            ->method('request')
            ->with(
                $this->stringContains('Context of previous chapters:'),
                'Text',
            )
            ->willReturn('Translated');

        $translator->translate('Text', 'Previous context');
    }

    public function testTranslateChunksLargeTextAndStitchesJsonResponses(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator(
            $client,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
        );

        // Каждый блок `<p>10chars</p>` = 17 байт. Три блока = 51 байт. chunk=20 → ровно 3 чанка по `</p>`.
        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p><p>cccccccccc</p>';

        $responses = [
            json_encode([
                'translation' => '<p>AAA</p>',
                'summary' => 'first chunk',
                'glossary' => ['A' => 'А'],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'translation' => '<p>BBB</p>',
                'summary' => 'first+second',
                'glossary' => ['A' => 'А', 'B' => 'Б'],
            ], JSON_THROW_ON_ERROR),
            json_encode([
                'translation' => '<p>CCC</p>',
                'summary' => 'all three',
                'glossary' => ['A' => 'А', 'B' => 'Б', 'C' => 'Ц'],
            ], JSON_THROW_ON_ERROR),
        ];

        $callIndex = 0;
        $client->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $system, string $user) use (&$callIndex, $responses): string {
                $this->assertStringContainsString('CHUNKED TRANSLATION MODE', $system);
                if ($callIndex === 0) {
                    $this->assertStringContainsString('Glossary so far (JSON):', $system);
                    $this->assertStringContainsString('{}', $system);
                }
                if ($callIndex === 1) {
                    $this->assertStringContainsString('first chunk', $system);
                    $this->assertStringContainsString('"A":"А"', $system);
                }
                if ($callIndex === 2) {
                    $this->assertStringContainsString('first+second', $system);
                    $this->assertStringContainsString('"B":"Б"', $system);
                }
                return $responses[$callIndex++];
            });

        $result = $translator->translate($text);

        $this->assertSame('<p>AAA</p><p>BBB</p><p>CCC</p>', $result);
    }

    public function testTranslateStripsMarkdownFencesFromJsonResponse(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator(
            $client,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
        );

        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>';

        $client->method('request')->willReturnOnConsecutiveCalls(
            "```json\n" . json_encode([
                'translation' => '<p>X</p>',
                'summary' => 's1',
                'glossary' => [],
            ], JSON_THROW_ON_ERROR) . "\n```",
            "```\n" . json_encode([
                'translation' => '<p>Y</p>',
                'summary' => 's2',
                'glossary' => [],
            ], JSON_THROW_ON_ERROR) . "\n```",
        );

        $this->assertSame('<p>X</p><p>Y</p>', $translator->translate($text));
    }

    public function testTranslateThrowsOnInvalidJson(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator(
            $client,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
        );

        $client->method('request')->willReturn('not a json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Chunk #1');

        $translator->translate('<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>');
    }

    public function testTranslateThrowsOnMissingTranslationField(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator(
            $client,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
        );

        $client->method('request')->willReturn(json_encode([
            'summary' => 'x',
            'glossary' => [],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing or empty "translation"');

        $translator->translate('<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>');
    }

    public function testChunkCacheWritesEachChunkAndHydratesOnSecondRun(): void
    {
        $cacheFile = $this->tempCacheFile();
        $scope = new TranslationScope('book-1', 'OEBPS/chap.xhtml');
        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p><p>cccccccccc</p>';

        // === Прогон №1: всё успешно, должны быть 3 LLM-запроса и 3 записи в кеше. ===
        $client1 = $this->createMock(LlmClientInterface::class);
        $client1->expects($this->exactly(3))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                json_encode(['translation' => '<p>А</p>', 'summary' => 's1', 'glossary' => ['a' => 'А']], JSON_THROW_ON_ERROR),
                json_encode(['translation' => '<p>Б</p>', 'summary' => 's2', 'glossary' => ['a' => 'А', 'b' => 'Б']], JSON_THROW_ON_ERROR),
                json_encode(['translation' => '<p>В</p>', 'summary' => 's3', 'glossary' => ['a' => 'А', 'b' => 'Б', 'c' => 'В']], JSON_THROW_ON_ERROR),
            );

        $translator1 = new LlmTranslator(
            $client1,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );

        $result1 = $translator1->translate($text, '', $scope);
        $this->assertSame('<p>А</p><p>Б</p><p>В</p>', $result1);

        $raw = json_decode((string) file_get_contents($cacheFile), true);
        $this->assertCount(3, $raw['book-1']['OEBPS/chap.xhtml']);

        // === Прогон №2: тот же текст и scope, LLM-клиент НЕ должен быть позван ни разу. ===
        $client2 = $this->createMock(LlmClientInterface::class);
        $client2->expects($this->never())->method('request');

        $translator2 = new LlmTranslator(
            $client2,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );

        $result2 = $translator2->translate($text, '', $scope);
        $this->assertSame('<p>А</p><p>Б</p><p>В</p>', $result2, 'Result on second run must be identical to first run');
    }

    public function testChunkCacheResumesAfterMidChapterFailure(): void
    {
        $cacheFile = $this->tempCacheFile();
        $scope = new TranslationScope('book-1', 'OEBPS/chap.xhtml');
        // 5 чанков, каждый ровно один блок `<p>...</p>` = 17 байт. chunk=20 → 5 ровных чанков.
        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p><p>cccccccccc</p><p>dddddddddd</p><p>eeeeeeeeee</p>';

        // === Прогон №1: первые 2 чанка успешны, на 3-м клиент падает. ===
        $client1 = $this->createMock(LlmClientInterface::class);
        $client1->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $system, string $user): string {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    return json_encode(['translation' => '<p>А</p>', 'summary' => 's1', 'glossary' => ['a' => 'А']], JSON_THROW_ON_ERROR);
                }
                if ($call === 2) {
                    return json_encode(['translation' => '<p>Б</p>', 'summary' => 's2', 'glossary' => ['a' => 'А', 'b' => 'Б']], JSON_THROW_ON_ERROR);
                }
                throw new \RuntimeException('simulated network failure on chunk #3');
            });

        $translator1 = new LlmTranslator(
            $client1,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );

        try {
            $translator1->translate($text, '', $scope);
            $this->fail('Expected RuntimeException to propagate from failed chunk');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated network failure on chunk #3', $e->getMessage());
        }

        // На диске должны быть РОВНО 2 записи — за чанки 0 и 1.
        $raw = json_decode((string) file_get_contents($cacheFile), true);
        $this->assertCount(2, $raw['book-1']['OEBPS/chap.xhtml']);

        // === Прогон №2: возобновляем. LLM должен быть позван ровно 3 раза — на чанки 2, 3, 4. ===
        // Если бы кеш не работал — позвали бы 5 раз. Это и есть главная проверка.
        $client2 = $this->createMock(LlmClientInterface::class);
        $seenUserPrompts = [];
        $client2->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $system, string $user) use (&$seenUserPrompts): string {
                $seenUserPrompts[] = $user;
                static $call = 0;
                $call++;
                $letters = ['c' => 'В', 'd' => 'Г', 'e' => 'Д'];
                $letter = match ($call) {
                    1 => 'c',
                    2 => 'd',
                    3 => 'e',
                    default => 'x',
                };
                return json_encode([
                    'translation' => '<p>' . $letters[$letter] . '</p>',
                    'summary' => 's' . ($call + 2),
                    'glossary' => [],
                ], JSON_THROW_ON_ERROR);
            });

        $translator2 = new LlmTranslator(
            $client2,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );

        $result = $translator2->translate($text, '', $scope);

        // Финальная склейка содержит чанки 0,1 из кеша + 2,3,4 из новых LLM-ответов.
        $this->assertSame('<p>А</p><p>Б</p><p>В</p><p>Г</p><p>Д</p>', $result);

        // И в user-prompt'ах должны быть только чанки 2..4, а не 0..1.
        $this->assertContains('<p>cccccccccc</p>', $seenUserPrompts);
        $this->assertContains('<p>dddddddddd</p>', $seenUserPrompts);
        $this->assertContains('<p>eeeeeeeeee</p>', $seenUserPrompts);
        $this->assertNotContains('<p>aaaaaaaaaa</p>', $seenUserPrompts);
        $this->assertNotContains('<p>bbbbbbbbbb</p>', $seenUserPrompts);
    }

    public function testChunkCacheHydratesGlossaryAndSummaryFromCacheIntoNextChunkPrompt(): void
    {
        $cacheFile = $this->tempCacheFile();
        $scope = new TranslationScope('book-1', 'OEBPS/chap.xhtml');
        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>';

        // Прогрев: 1-й чанк успешен, 2-й падает.
        $clientWarm = $this->createMock(LlmClientInterface::class);
        $clientWarm->method('request')->willReturnCallback(function (): string {
            static $call = 0;
            $call++;
            if ($call === 1) {
                return json_encode([
                    'translation' => '<p>А</p>',
                    'summary' => 'CACHED-SUMMARY',
                    'glossary' => ['Name' => 'Имя'],
                ], JSON_THROW_ON_ERROR);
            }
            throw new \RuntimeException('boom');
        });

        $translatorWarm = new LlmTranslator(
            $clientWarm,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );
        try {
            $translatorWarm->translate($text, '', $scope);
        } catch (\RuntimeException) {
            // expected
        }

        // Resume: видим, что во 2-й запрос пошёл prompt с CACHED-SUMMARY и Name→Имя.
        $client2 = $this->createMock(LlmClientInterface::class);
        $capturedSystem = null;
        $client2->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $system) use (&$capturedSystem): string {
                $capturedSystem = $system;
                return json_encode([
                    'translation' => '<p>Б</p>',
                    'summary' => 'all done',
                    'glossary' => [],
                ], JSON_THROW_ON_ERROR);
            });

        $translator2 = new LlmTranslator(
            $client2,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );
        $translator2->translate($text, '', $scope);

        $this->assertNotNull($capturedSystem);
        $this->assertStringContainsString('CACHED-SUMMARY', $capturedSystem);
        $this->assertStringContainsString('"Name":"Имя"', $capturedSystem);
    }

    public function testChunkCacheInvalidatesWhenChunkContentChanges(): void
    {
        $cacheFile = $this->tempCacheFile();
        $scope = new TranslationScope('book-1', 'OEBPS/chap.xhtml');

        $text1 = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>';
        $text2 = '<p>aaaaaaaaaa</p><p>BBBBBBBBBB</p>'; // 2-й чанк изменился

        $client1 = $this->createMock(LlmClientInterface::class);
        $client1->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            json_encode(['translation' => '<p>А</p>', 'summary' => 's1', 'glossary' => []], JSON_THROW_ON_ERROR),
            json_encode(['translation' => '<p>Б</p>', 'summary' => 's2', 'glossary' => []], JSON_THROW_ON_ERROR),
        );
        $t1 = new LlmTranslator($client1, 'Russian', 'Translate:', new TextSplitter(), chunkSizeBytes: 20, chunkCache: new FileTranslationChunkCache($cacheFile));
        $t1->translate($text1, '', $scope);

        // 2-й прогон: чанк 0 такой же → cache hit; чанк 1 другой → cache miss → ровно 1 LLM-запрос.
        $client2 = $this->createMock(LlmClientInterface::class);
        $client2->expects($this->once())->method('request')->willReturn(
            json_encode(['translation' => '<p>Б2</p>', 'summary' => 's2-new', 'glossary' => []], JSON_THROW_ON_ERROR),
        );
        $t2 = new LlmTranslator($client2, 'Russian', 'Translate:', new TextSplitter(), chunkSizeBytes: 20, chunkCache: new FileTranslationChunkCache($cacheFile));
        $result = $t2->translate($text2, '', $scope);

        $this->assertSame('<p>А</p><p>Б2</p>', $result);
    }

    public function testChunkCacheIgnoredWhenScopeNotProvided(): void
    {
        $cacheFile = $this->tempCacheFile();
        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>';

        $client = $this->createMock(LlmClientInterface::class);
        // Кеш есть, но scope нет → должен всегда звать LLM, ничего не писать в файл.
        $client->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            json_encode(['translation' => '<p>X</p>', 'summary' => 's', 'glossary' => []], JSON_THROW_ON_ERROR),
            json_encode(['translation' => '<p>Y</p>', 'summary' => 's', 'glossary' => []], JSON_THROW_ON_ERROR),
        );

        $translator = new LlmTranslator(
            $client,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
            chunkCache: new FileTranslationChunkCache($cacheFile),
        );

        $translator->translate($text);

        // Файл не должен появиться, потому что set() не вызывался.
        $this->assertFileDoesNotExist($cacheFile);
    }

    public function testTranslateOuterContextSeedsRunningSummaryOnChunkedPath(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $translator = new LlmTranslator(
            $client,
            'Russian',
            'Translate:',
            new TextSplitter(),
            chunkSizeBytes: 20,
        );

        $client->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function (string $system) {
                static $i = 0;
                if ($i === 0) {
                    $this->assertStringContainsString('OUTER-CTX', $system);
                }
                $i++;
                return json_encode([
                    'translation' => '<p>x</p>',
                    'summary' => 'new',
                    'glossary' => [],
                ], JSON_THROW_ON_ERROR);
            });

        $translator->translate(
            '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p>',
            'OUTER-CTX',
        );
    }
}
