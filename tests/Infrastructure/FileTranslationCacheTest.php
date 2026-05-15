<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Infrastructure\FileTranslationCache;
use PHPUnit\Framework\TestCase;

class FileTranslationCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/translation_cache_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }

    public function testGetReturnsNullWhenMiss(): void
    {
        $cache = new FileTranslationCache($this->cacheFile);

        $this->assertNull($cache->get('book', 'OEBPS/chap1.xhtml'));
    }

    public function testSetAndGetReturnsValueOnHit(): void
    {
        $cache = new FileTranslationCache($this->cacheFile);
        $cache->set('book', 'OEBPS/chap1.xhtml', 'translated text');

        $this->assertSame('translated text', $cache->get('book', 'OEBPS/chap1.xhtml'));
    }

    public function testSetOverwritesPreviousValue(): void
    {
        $cache = new FileTranslationCache($this->cacheFile);
        $cache->set('book', 'OEBPS/chap1.xhtml', 'old translation');
        $cache->set('book', 'OEBPS/chap1.xhtml', 'new translation');

        $this->assertSame('new translation', $cache->get('book', 'OEBPS/chap1.xhtml'));
    }

    public function testPersistsAcrossInstances(): void
    {
        $first = new FileTranslationCache($this->cacheFile);
        $first->set('book', 'OEBPS/chap1.xhtml', 'translated');

        $second = new FileTranslationCache($this->cacheFile);

        $this->assertSame('translated', $second->get('book', 'OEBPS/chap1.xhtml'));
    }

    public function testIsolatesBetweenBooks(): void
    {
        $cache = new FileTranslationCache($this->cacheFile);
        $cache->set('book-a', 'OEBPS/chap1.xhtml', 'a-c1');
        $cache->set('book-b', 'OEBPS/chap1.xhtml', 'b-c1');

        $this->assertSame('a-c1', $cache->get('book-a', 'OEBPS/chap1.xhtml'));
        $this->assertSame('b-c1', $cache->get('book-b', 'OEBPS/chap1.xhtml'));
        $this->assertNull($cache->get('book-c', 'OEBPS/chap1.xhtml'));
    }

    public function testIsolatesBetweenRelativeFilePaths(): void
    {
        $cache = new FileTranslationCache($this->cacheFile);
        $cache->set('book-a', 'OEBPS/chap1.xhtml', 'a-c1');
        $cache->set('book-a', 'OEBPS/chap2.xhtml', 'a-c2');

        $this->assertSame('a-c1', $cache->get('book-a', 'OEBPS/chap1.xhtml'));
        $this->assertSame('a-c2', $cache->get('book-a', 'OEBPS/chap2.xhtml'));
        $this->assertNull($cache->get('book-a', 'OEBPS/chap3.xhtml'));
    }
}
