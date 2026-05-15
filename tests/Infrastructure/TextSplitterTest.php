<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Infrastructure\TextSplitter;
use PHPUnit\Framework\TestCase;

class TextSplitterTest extends TestCase
{
    public function testSplitSmallText(): void
    {
        $splitter = new TextSplitter();
        $text = 'Small text';
        $chunks = $splitter->split($text, 20);

        $this->assertCount(1, $chunks);
        $this->assertEquals($text, $chunks[0]);
    }

    public function testSplitByNewlines(): void
    {
        $splitter = new TextSplitter();
        $text = "Line 1\nLine 2\nLine 3";
        // Max size 8 will take "Line 1\n" (7 bytes)
        $chunks = $splitter->split($text, 8);

        $this->assertCount(3, $chunks);
        $this->assertEquals("Line 1\n", $chunks[0]);
        $this->assertEquals("Line 2\n", $chunks[1]);
        $this->assertEquals('Line 3', $chunks[2]);
    }

    public function testSplitHardLimit(): void
    {
        $splitter = new TextSplitter();
        $text = 'NoNewlinesHere';
        $chunks = $splitter->split($text, 5);

        $this->assertCount(3, $chunks);
        $this->assertEquals('NoNew', $chunks[0]);
        $this->assertEquals('lines', $chunks[1]);
        $this->assertEquals('Here', $chunks[2]);
    }

    public function testSplitExactSize(): void
    {
        $splitter = new TextSplitter();
        $text = "12345\n67890";
        $chunks = $splitter->split($text, 6);

        $this->assertCount(2, $chunks);
        $this->assertEquals("12345\n", $chunks[0]);
        $this->assertEquals('67890', $chunks[1]);
    }

    public function testReconstructText(): void
    {
        $splitter = new TextSplitter();
        $text = "This is a longer text\nwith several lines\nand should be reconstructed perfectly.";
        $chunks = $splitter->split($text, 10);

        $this->assertEquals($text, implode('', $chunks));
    }

    public function testSplitXhtmlSmallReturnsSingleChunk(): void
    {
        $splitter = new TextSplitter();
        $text = '<p>Hello</p>';

        $this->assertSame([$text], $splitter->splitXhtml($text, 100));
    }

    public function testSplitXhtmlCutsOnBlockClose(): void
    {
        $splitter = new TextSplitter();
        $text = '<p>aaaaaaaaaa</p><p>bbbbbbbbbb</p><p>cccccccccc</p>';
        $chunks = $splitter->splitXhtml($text, 25);

        $this->assertSame($text, implode('', $chunks));
        foreach ($chunks as $i => $chunk) {
            if ($i === array_key_last($chunks)) {
                continue;
            }
            $this->assertStringEndsWith('</p>', $chunk, "Chunk #{$i} must end on a block-close tag");
        }
    }

    public function testSplitXhtmlPreservesAllBytes(): void
    {
        $splitter = new TextSplitter();
        $text = str_repeat('<p>' . str_repeat('x', 200) . '</p>', 50);

        $chunks = $splitter->splitXhtml($text, 1024);

        $this->assertSame($text, implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(1024, strlen($chunk));
        }
    }

    public function testSplitXhtmlFallsBackToNewlineWhenNoBlockClose(): void
    {
        $splitter = new TextSplitter();
        $text = "line1\nline2\nline3\nline4\n";
        $chunks = $splitter->splitXhtml($text, 12);

        $this->assertSame($text, implode('', $chunks));
        $this->assertGreaterThan(1, count($chunks));
    }

    public function testSplitXhtmlHardCutsWhenNoBoundary(): void
    {
        $splitter = new TextSplitter();
        $text = str_repeat('a', 30);
        $chunks = $splitter->splitXhtml($text, 10);

        $this->assertCount(3, $chunks);
        $this->assertSame($text, implode('', $chunks));
    }

    public function testSplitXhtmlRecognizesHeadingsAndLists(): void
    {
        $splitter = new TextSplitter();
        $text = '<h1>Title</h1><ul><li>one</li><li>two</li></ul><p>para</p>';
        $chunks = $splitter->splitXhtml($text, 25);

        $this->assertSame($text, implode('', $chunks));
        $this->assertGreaterThan(1, count($chunks));
    }
}
