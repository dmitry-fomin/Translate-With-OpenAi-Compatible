<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Domain\Book;
use App\Infrastructure\EpubHandler;
use PHPUnit\Framework\TestCase;

class EpubHandlerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/epub-handler-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmpRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRoot)) {
            self::removeRecursive($this->tmpRoot);
        }
    }

    public function testLoadParsesEpubWithOebpsLayout(): void
    {
        $bookDir = $this->tmpRoot . '/book-oebps';
        $this->writeEpubFixture(
            $bookDir,
            'OEBPS',
            [
                'chap1.xhtml' => '<html><body><p>Hello</p></body></html>',
                'chap2.xhtml' => '<html><body><p>World</p></body></html>',
            ],
            'Sample Title',
        );

        $book = (new EpubHandler())->load($bookDir);

        $this->assertSame('Sample Title', $book->getTitle());
        $chapters = $book->getChapters();
        $this->assertCount(2, $chapters);
        $this->assertSame('chap1', $chapters[0]->getId());
        $this->assertSame('OEBPS/chap1.xhtml', $chapters[0]->getRelativeFilePath());
        $this->assertSame('<html><body><p>Hello</p></body></html>', $chapters[0]->getContent());
        $this->assertSame('chap2', $chapters[1]->getId());
        $this->assertSame('OEBPS/chap2.xhtml', $chapters[1]->getRelativeFilePath());
    }

    public function testLoadParsesEpubWithOpfInRoot(): void
    {
        $bookDir = $this->tmpRoot . '/book-root';
        $this->writeEpubFixture(
            $bookDir,
            '',
            ['only.xhtml' => '<html><body><p>Solo</p></body></html>'],
            'Root Layout',
        );

        $book = (new EpubHandler())->load($bookDir);

        $this->assertSame('Root Layout', $book->getTitle());
        $chapters = $book->getChapters();
        $this->assertCount(1, $chapters);
        $this->assertSame('only.xhtml', $chapters[0]->getRelativeFilePath());
    }

    public function testLoadThrowsWhenOpfMissing(): void
    {
        $bookDir = $this->tmpRoot . '/no-opf';
        mkdir($bookDir, 0o777, true);
        file_put_contents($bookDir . '/readme.txt', 'no opf here');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/content\.opf not found/');

        (new EpubHandler())->load($bookDir);
    }

    public function testLoadThrowsWhenPathDoesNotExist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Path not found/');

        (new EpubHandler())->load($this->tmpRoot . '/does-not-exist');
    }

    public function testSaveCopiesSourceAndOverwritesTranslatedChapters(): void
    {
        $bookDir = $this->tmpRoot . '/book-save';
        $this->writeEpubFixture(
            $bookDir,
            'OEBPS',
            [
                'chap1.xhtml' => '<html><body><p>Hello</p></body></html>',
                'chap2.xhtml' => '<html><body><p>World</p></body></html>',
            ],
            'Save Title',
        );

        $handler = new EpubHandler();
        $book = $handler->load($bookDir);

        $chapters = $book->getChapters();
        $chapters[0]->setTranslatedContent('<html><body><p>Привет</p></body></html>');

        $outDir = $this->tmpRoot . '/out';
        $handler->save($book, $outDir);

        $this->assertSame(
            '<html><body><p>Привет</p></body></html>',
            file_get_contents($outDir . '/OEBPS/chap1.xhtml'),
            'Переведённая глава перезаписана',
        );
        $this->assertSame(
            '<html><body><p>World</p></body></html>',
            file_get_contents($outDir . '/OEBPS/chap2.xhtml'),
            'Непереведённая глава скопирована как есть',
        );
        $this->assertFileExists($outDir . '/OEBPS/content.opf', 'opf скопирован');

        $this->assertSame(
            '<html><body><p>Hello</p></body></html>',
            file_get_contents($bookDir . '/OEBPS/chap1.xhtml'),
            'Источник не повреждён',
        );
    }

    public function testSaveThrowsWhenLoadNotCalled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Source path not set/');

        (new EpubHandler())->save(new Book('No Load'), $this->tmpRoot . '/out');
    }

    public function testPackProducesValidEpub(): void
    {
        $bookDir = $this->tmpRoot . '/book-pack';
        $this->writeEpubFixture(
            $bookDir,
            'OEBPS',
            ['chap1.xhtml' => '<html><body><p>Hi</p></body></html>'],
            'Pack Title',
        );
        file_put_contents($bookDir . '/mimetype', 'application/epub+zip');
        mkdir($bookDir . '/META-INF', 0o777, true);
        file_put_contents(
            $bookDir . '/META-INF/container.xml',
            '<?xml version="1.0"?><container><rootfiles><rootfile full-path="OEBPS/content.opf"/></rootfiles></container>',
        );

        $outFile = $this->tmpRoot . '/result.epub';
        (new EpubHandler())->pack($bookDir, $outFile);

        $this->assertFileExists($outFile);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($outFile) === true);

        $this->assertSame('mimetype', $zip->getNameIndex(0), 'mimetype первый');
        $stat = $zip->statIndex(0);
        $this->assertSame(\ZipArchive::CM_STORE, $stat['comp_method'], 'mimetype stored, не сжатый');
        $this->assertSame('application/epub+zip', $zip->getFromName('mimetype'));
        $this->assertSame(
            '<html><body><p>Hi</p></body></html>',
            $zip->getFromName('OEBPS/chap1.xhtml'),
        );
        $this->assertNotFalse($zip->getFromName('OEBPS/content.opf'));
        $this->assertNotFalse($zip->getFromName('META-INF/container.xml'));
        $zip->close();
    }

    public function testLoadAcceptsEpubFile(): void
    {
        $bookDir = $this->tmpRoot . '/book-zip-src';
        $this->writeEpubFixture(
            $bookDir,
            'OEBPS',
            ['chap1.xhtml' => '<html><body><p>Zipped</p></body></html>'],
            'Zipped Title',
        );
        file_put_contents($bookDir . '/mimetype', 'application/epub+zip');

        $epubFile = $this->tmpRoot . '/book.epub';
        (new EpubHandler())->pack($bookDir, $epubFile);

        $cacheDir = $this->tmpRoot . '/cache';
        $book = (new EpubHandler($cacheDir))->load($epubFile);

        $this->assertSame('Zipped Title', $book->getTitle());
        $chapters = $book->getChapters();
        $this->assertCount(1, $chapters);
        $this->assertSame('<html><body><p>Zipped</p></body></html>', $chapters[0]->getContent());

        $extracted = glob($cacheDir . '/translater-llm-*');
        $this->assertNotEmpty($extracted, 'EPUB распакован в указанный cacheDir, а не в системный temp');
    }

    public function testRelativeFilePathIsStableAcrossEquivalentInputPaths(): void
    {
        // Регрессия: relativeFilePath должен быть одинаков, как бы ни был оформлен путь к EPUB
        // и к cacheDir. До фикса cacheDir вида ".../bin/../.cache" → префикс ".cache" не вычитался
        // из резолвленного opfPath, и в кеш писалось "Users/.../.cache/.../OEBPS/chap1.xhtml".
        $bookDir = $this->tmpRoot . '/book-stable';
        $this->writeEpubFixture(
            $bookDir,
            'OEBPS',
            ['chap1.xhtml' => '<html><body><p>x</p></body></html>'],
            'Stable',
        );
        file_put_contents($bookDir . '/mimetype', 'application/epub+zip');

        $epubFile = $this->tmpRoot . '/stable.epub';
        (new EpubHandler())->pack($bookDir, $epubFile);

        // 1) cacheDir с компонентом "..", который не вычитается без realpath
        mkdir($this->tmpRoot . '/bin', 0o777, true);
        mkdir($this->tmpRoot . '/cache-a', 0o777, true);
        $cacheDirA = $this->tmpRoot . '/bin/../cache-a';
        $bookA = (new EpubHandler($cacheDirA))->load($epubFile);

        // 2) Тот же cacheDir, но в нормализованной форме
        $cacheDirB = $this->tmpRoot . '/cache-a';
        $bookB = (new EpubHandler($cacheDirB))->load($epubFile);

        $pathA = $bookA->getChapters()[0]->getRelativeFilePath();
        $pathB = $bookB->getChapters()[0]->getRelativeFilePath();

        $this->assertSame('OEBPS/chap1.xhtml', $pathA);
        $this->assertSame($pathA, $pathB, 'relativeFilePath не зависит от формы cacheDir');
    }

    public function testPackThrowsWithoutMimetype(): void
    {
        $bookDir = $this->tmpRoot . '/no-mimetype';
        mkdir($bookDir, 0o777, true);
        file_put_contents($bookDir . '/whatever.txt', 'no mimetype');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mimetype/');

        (new EpubHandler())->pack($bookDir, $this->tmpRoot . '/x.epub');
    }

    /**
     * @param array<string, string> $chapters relativePath (внутри $contentDir) => xhtml content
     */
    private function writeEpubFixture(string $root, string $contentDir, array $chapters, string $title): void
    {
        mkdir($root, 0o777, true);
        $base = $contentDir === '' ? $root : $root . '/' . $contentDir;
        if (!is_dir($base)) {
            mkdir($base, 0o777, true);
        }

        $manifestItems = '';
        $spineItems = '';
        foreach ($chapters as $href => $body) {
            $id = pathinfo($href, PATHINFO_FILENAME);
            file_put_contents($base . '/' . $href, $body);
            $manifestItems .= sprintf(
                '<item id="%s" href="%s" media-type="application/xhtml+xml"/>',
                htmlspecialchars($id, ENT_XML1),
                htmlspecialchars($href, ENT_XML1),
            );
            $spineItems .= sprintf('<itemref idref="%s"/>', htmlspecialchars($id, ENT_XML1));
        }

        $opf = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">
              <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
                <dc:title>{$title}</dc:title>
              </metadata>
              <manifest>{$manifestItems}</manifest>
              <spine>{$spineItems}</spine>
            </package>
            XML;

        file_put_contents($base . '/content.opf', $opf);
    }

    private static function removeRecursive(string $path): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($path);
    }
}
