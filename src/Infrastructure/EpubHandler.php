<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Book;
use App\Domain\Chapter;
use App\Domain\CoverImage;
use App\Domain\EpubRepositoryInterface;
use DOMDocument;
use DOMElement;
use DOMXPath;

class EpubHandler implements EpubRepositoryInterface
{
    private string $sourcePath;

    public function __construct(
        private readonly ?string $cacheDir = null,
    ) {
    }

    public function load(string $path): Book
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new \RuntimeException("Path not found: $path");
        }

        if (is_file($resolved)) {
            $resolved = $this->extractEpubToTemp($resolved);
        }

        // Нормализуем путь так же, как findOpf() через getRealPath(): иначе relativeFilePath
        // станет нестабильным между запусками из-за симлинков (/var → /private/var, bin/../.cache → .cache)
        // и сломает translation cache.
        $normalized = realpath($resolved);
        if ($normalized === false) {
            throw new \RuntimeException("Failed to resolve extracted EPUB path: $resolved");
        }
        $this->sourcePath = $normalized;
        $opfPath = $this->findOpf($this->sourcePath);

        $dom = new DOMDocument();
        $dom->load($opfPath);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('opf', 'http://www.idpf.org/2007/opf');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');

        $title = $xpath->query('//dc:title')->item(0)?->nodeValue ?? 'Unknown Title';
        $author = $xpath->query('//dc:creator')->item(0)?->nodeValue;
        $author = (is_string($author) && trim($author) !== '') ? trim($author) : null;
        $book = new Book($title, [], $author);

        $manifestItems = [];
        /** @var array<string, array{href: string, mediaType: string, properties: string}> $manifestMeta */
        $manifestMeta = [];
        foreach ($xpath->query('//opf:manifest/opf:item') ?: [] as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }
            $id = $item->getAttribute('id');
            $manifestItems[$id] = $item->getAttribute('href');
            $manifestMeta[$id] = [
                'href' => $item->getAttribute('href'),
                'mediaType' => $item->getAttribute('media-type'),
                'properties' => $item->getAttribute('properties'),
            ];
        }

        $baseDir = dirname($opfPath);
        $relativeBaseDir = trim(str_replace($this->sourcePath, '', $baseDir), '/');

        $cover = $this->detectCover($xpath, $manifestMeta, $baseDir, $relativeBaseDir);
        if ($cover !== null) {
            $book->setCover($cover);
        }

        foreach ($xpath->query('//opf:spine/opf:itemref') ?: [] as $itemRef) {
            if (!$itemRef instanceof DOMElement) {
                continue;
            }
            $idref = $itemRef->getAttribute('idref');
            $href = $manifestItems[$idref] ?? null;
            if ($href && (str_ends_with($href, '.xhtml') || str_ends_with($href, '.html'))) {
                $absolutePath = $baseDir . '/' . $href;
                $relativeFilePath = ($relativeBaseDir ? $relativeBaseDir . '/' : '') . $href;

                if (file_exists($absolutePath)) {
                    $content = file_get_contents($absolutePath);
                    $chapterTitle = $this->extractChapterTitle($content) ?? $idref;
                    $book->addChapter(new Chapter($idref, $chapterTitle, $content, $relativeFilePath, $absolutePath));
                }
            }
        }

        return $book;
    }

    public function save(Book $book, string $outputPath): void
    {
        if (empty($this->sourcePath)) {
            throw new \RuntimeException('Source path not set. Load a book first.');
        }

        $this->copyRecursive($this->sourcePath, $outputPath);

        foreach ($book->getChapters() as $chapter) {
            if ($chapter->getTranslatedContent()) {
                $targetFile = $outputPath . DIRECTORY_SEPARATOR . $chapter->getRelativeFilePath();
                $targetDir = dirname($targetFile);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0o777, true);
                }
                file_put_contents($targetFile, $chapter->getTranslatedContent());
            }
        }

        $cover = $book->getCover();
        if ($cover !== null && $cover->getTranslatedBytes() !== null) {
            $targetFile = $outputPath . DIRECTORY_SEPARATOR . $cover->getRelativeFilePath();
            $targetDir = dirname($targetFile);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0o777, true);
            }
            file_put_contents($targetFile, $cover->getTranslatedBytes());
        }
    }

    private function extractChapterTitle(string $content): ?string
    {
        if (!preg_match('~<title[^>]*>(.*?)</title>~si', $content, $m)) {
            return null;
        }
        $title = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(preg_replace('~\s+~u', ' ', $title) ?? '');
        return $title === '' ? null : $title;
    }

    /**
     * @param array<string, array{href: string, mediaType: string, properties: string}> $manifestMeta
     */
    private function detectCover(DOMXPath $xpath, array $manifestMeta, string $baseDir, string $relativeBaseDir): ?CoverImage
    {
        $coverId = null;

        // EPUB 3: <item properties="cover-image">
        foreach ($manifestMeta as $id => $meta) {
            $properties = preg_split('/\s+/', $meta['properties']) ?: [];
            if (in_array('cover-image', $properties, true)) {
                $coverId = $id;
                break;
            }
        }

        // EPUB 2: <meta name="cover" content="id-of-cover-item"/>
        if ($coverId === null) {
            $metaCover = $xpath->query('//opf:metadata/opf:meta[@name="cover"]')->item(0);
            if ($metaCover instanceof DOMElement) {
                $candidate = $metaCover->getAttribute('content');
                if ($candidate !== '' && isset($manifestMeta[$candidate])) {
                    $coverId = $candidate;
                }
            }
        }

        // Fallback: id содержит "cover" и media-type image/*
        if ($coverId === null) {
            foreach ($manifestMeta as $id => $meta) {
                if (str_starts_with($meta['mediaType'], 'image/') && str_contains(strtolower($id), 'cover')) {
                    $coverId = $id;
                    break;
                }
            }
        }

        if ($coverId === null) {
            return null;
        }

        $meta = $manifestMeta[$coverId];
        if (!str_starts_with($meta['mediaType'], 'image/')) {
            return null;
        }

        $absolutePath = $baseDir . '/' . $meta['href'];
        if (!is_file($absolutePath)) {
            return null;
        }

        $bytes = file_get_contents($absolutePath);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $relativeFilePath = ($relativeBaseDir !== '' ? $relativeBaseDir . '/' : '') . $meta['href'];

        return new CoverImage($bytes, $meta['mediaType'], $relativeFilePath, $absolutePath);
    }

    public function pack(string $sourceFolder, string $outputFile): void
    {
        $realSource = realpath($sourceFolder);
        if ($realSource === false || !is_dir($realSource)) {
            throw new \RuntimeException("Source folder not found: $sourceFolder");
        }

        $mimetypePath = $realSource . DIRECTORY_SEPARATOR . 'mimetype';
        if (!is_file($mimetypePath)) {
            throw new \RuntimeException("mimetype file not found in $realSource — is it really an unpacked EPUB?");
        }

        if (is_file($outputFile)) {
            unlink($outputFile);
        }
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0o777, true);
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($outputFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException("Failed to create EPUB archive: $outputFile (code $opened)");
        }

        $mimetype = trim((string) file_get_contents($mimetypePath));
        if ($mimetype === '') {
            $mimetype = 'application/epub+zip';
        }
        $zip->addFromString('mimetype', $mimetype);
        $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realSource, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($realSource))), '/');
            if ($relative === '' || $relative === 'mimetype') {
                continue;
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            $zip->addFile($absolute, $relative);
            $zip->setCompressionName($relative, \ZipArchive::CM_DEFLATE);
        }

        if (!$zip->close()) {
            throw new \RuntimeException("Failed to finalize EPUB archive: $outputFile");
        }
    }

    private function extractEpubToTemp(string $epubFile): string
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($epubFile);
        if ($opened !== true) {
            throw new \RuntimeException("Cannot open EPUB file: $epubFile (code $opened)");
        }

        $baseDir = $this->cacheDir ?? sys_get_temp_dir();
        $tempDir = $baseDir . DIRECTORY_SEPARATOR
            . 'translater-llm-' . hash('sha256', $epubFile . filemtime($epubFile));

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0o777, true);
        }

        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            throw new \RuntimeException("Failed to extract EPUB to $tempDir");
        }
        $zip->close();

        return $tempDir;
    }

    private function findOpf(string $path): string
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        foreach ($it as $file) {
            if ($file->getExtension() === 'opf') {
                return $file->getRealPath();
            }
        }
        throw new \RuntimeException("content.opf not found in $path");
    }

    private function copyRecursive(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0o777, true);
        }
        $it = new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $file) {
            $target = $dest . DIRECTORY_SEPARATOR . $files->getSubPathName();
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target);
                }
            } else {
                copy($file->getRealPath(), $target);
            }
        }
    }
}
