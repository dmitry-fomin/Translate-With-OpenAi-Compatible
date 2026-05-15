<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Infrastructure\LLM\LlmClientInterface;
use App\Infrastructure\LlmBookMetadataTranslator;
use PHPUnit\Framework\TestCase;

final class LlmBookMetadataTranslatorTest extends TestCase
{
    public function testReturnsTitleAndAuthorFromPlainJson(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects($this->once())
            ->method('request')
            ->with('SYS', $this->stringContains('Empire of the Dawn'))
            ->willReturn('{"title":"Империя рассвета","author":"Джей Кристофф"}');

        $tr = new LlmBookMetadataTranslator($client, 'SYS', 'Russian');
        $out = $tr->translate('Empire of the Dawn', 'Jay Kristoff');

        $this->assertSame('Империя рассвета', $out->title);
        $this->assertSame('Джей Кристофф', $out->author);
    }

    public function testStripsMarkdownFenceAroundJson(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('request')
            ->willReturn("```json\n{\"title\":\"X\",\"author\":null}\n```");

        $tr = new LlmBookMetadataTranslator($client, 'SYS', 'Russian');
        $out = $tr->translate('X', null);

        $this->assertSame('X', $out->title);
        $this->assertNull($out->author);
    }

    public function testNullAuthorWhenLlmReturnsEmptyString(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('request')
            ->willReturn('{"title":"X","author":""}');

        $tr = new LlmBookMetadataTranslator($client, 'SYS', 'Russian');
        $out = $tr->translate('X', null);

        $this->assertNull($out->author);
    }

    public function testThrowsOnMissingTitle(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('request')->willReturn('{"author":"X"}');

        $tr = new LlmBookMetadataTranslator($client, 'SYS', 'Russian');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/title/');
        $tr->translate('X', null);
    }

    public function testThrowsOnInvalidJson(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->method('request')->willReturn('not json at all');

        $tr = new LlmBookMetadataTranslator($client, 'SYS', 'Russian');

        $this->expectException(\RuntimeException::class);
        $tr->translate('X', null);
    }
}
