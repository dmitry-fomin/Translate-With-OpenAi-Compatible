<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Infrastructure\FileTranslationChunkCache;
use PHPUnit\Framework\TestCase;

class FileTranslationChunkCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/chunk_cache_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $this->assertNull($cache->get('book', 'chap', 0, 'somehash'));
    }

    public function testSetAndGet(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $cache->set('book', 'chap', 0, 'h1', '<p>tr</p>', 'sum1', ['A' => 'А']);

        $entry = $cache->get('book', 'chap', 0, 'h1');
        $this->assertNotNull($entry);
        $this->assertSame('<p>tr</p>', $entry['translation']);
        $this->assertSame('sum1', $entry['summary']);
        $this->assertSame(['A' => 'А'], $entry['glossary']);
    }

    public function testMissOnDifferentHash(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $cache->set('book', 'chap', 0, 'h1', 'tr', 'sum', []);

        $this->assertNotNull($cache->get('book', 'chap', 0, 'h1'));
        $this->assertNull($cache->get('book', 'chap', 0, 'h2'));
    }

    public function testMissOnDifferentIndex(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $cache->set('book', 'chap', 0, 'h1', 'tr', 'sum', []);

        $this->assertNull($cache->get('book', 'chap', 1, 'h1'));
    }

    public function testIsolatesBetweenBooksAndChapters(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $cache->set('book-a', 'chap-1', 0, 'h', 'a1', 's-a1', ['A' => 'А']);
        $cache->set('book-a', 'chap-2', 0, 'h', 'a2', 's-a2', []);
        $cache->set('book-b', 'chap-1', 0, 'h', 'b1', 's-b1', []);

        $this->assertSame('a1', $cache->get('book-a', 'chap-1', 0, 'h')['translation'] ?? null);
        $this->assertSame('a2', $cache->get('book-a', 'chap-2', 0, 'h')['translation'] ?? null);
        $this->assertSame('b1', $cache->get('book-b', 'chap-1', 0, 'h')['translation'] ?? null);
        $this->assertNull($cache->get('book-c', 'chap-1', 0, 'h'));
    }

    public function testPersistsAcrossInstances(): void
    {
        $first = new FileTranslationChunkCache($this->cacheFile);
        $first->set('book', 'chap', 2, 'h', 'tr', 'sum', ['k' => 'v']);

        $second = new FileTranslationChunkCache($this->cacheFile);
        $entry = $second->get('book', 'chap', 2, 'h');

        $this->assertNotNull($entry);
        $this->assertSame('tr', $entry['translation']);
        $this->assertSame('sum', $entry['summary']);
        $this->assertSame(['k' => 'v'], $entry['glossary']);
    }

    public function testWritesPersistImmediatelyOnSet(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $cache->set('book', 'chap', 0, 'h', 'tr', 'sum', []);

        $this->assertFileExists($this->cacheFile);
        $raw = (string) file_get_contents($this->cacheFile);
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('book', $decoded);
        $this->assertArrayHasKey('chap', $decoded['book']);
        $this->assertArrayHasKey('0:h', $decoded['book']['chap']);
        $this->assertSame('tr', $decoded['book']['chap']['0:h']['translation']);
    }

    public function testOverwriteUpdatesValue(): void
    {
        $cache = new FileTranslationChunkCache($this->cacheFile);
        $cache->set('book', 'chap', 0, 'h', 'old', 'old-sum', []);
        $cache->set('book', 'chap', 0, 'h', 'new', 'new-sum', ['x' => 'X']);

        $entry = $cache->get('book', 'chap', 0, 'h');
        $this->assertSame('new', $entry['translation'] ?? null);
        $this->assertSame('new-sum', $entry['summary'] ?? null);
        $this->assertSame(['x' => 'X'], $entry['glossary'] ?? null);
    }

    public function testGracefullyIgnoresPartiallyBrokenEntry(): void
    {
        file_put_contents($this->cacheFile, json_encode([
            'book' => ['chap' => ['0:h' => ['translation' => 'only this field']]],
        ], JSON_THROW_ON_ERROR));

        $cache = new FileTranslationChunkCache($this->cacheFile);
        $this->assertNull($cache->get('book', 'chap', 0, 'h'));
    }
}
