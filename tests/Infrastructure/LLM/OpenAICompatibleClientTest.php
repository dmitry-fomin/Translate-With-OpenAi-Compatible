<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\LLM;

use App\Infrastructure\LLM\OpenAICompatibleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class OpenAICompatibleClientTest extends TestCase
{
    public function testRequestThrowsOnMissingChoicesContent(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient('k', $httpClient, 'https://x', 'm');

        $httpClient->method('post')->willReturn(
            new Response(200, [], (string) json_encode(['error' => 'oops'])),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unexpected LLM response/i');

        $client->request('s', 'u');
    }

    public function testRequestThrowsOnEmptyContent(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient('k', $httpClient, 'https://x', 'm');

        $httpClient->method('post')->willReturn(
            new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => '']]],
            ])),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty content/i');

        $client->request('s', 'u');
    }

    public function testRequestSendsBearerModelAndDefaultTemperature(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient(
            apiKey: 'sk-test-123',
            httpClient: $httpClient,
            apiUrl: 'https://api.closerouter.dev/v1/chat/completions',
            model: 'openai/gpt-5.5',
        );

        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api.closerouter.dev/v1/chat/completions',
                $this->callback(function (array $options): bool {
                    $this->assertSame('Bearer sk-test-123', $options['headers']['Authorization']);
                    $this->assertSame('application/json', $options['headers']['Content-Type']);
                    $this->assertSame('openai/gpt-5.5', $options['json']['model']);
                    $this->assertSame(0.3, $options['json']['temperature']);
                    $this->assertSame('system', $options['json']['messages'][0]['role']);
                    $this->assertSame('Translate to Russian:', $options['json']['messages'][0]['content']);
                    $this->assertSame('user', $options['json']['messages'][1]['role']);
                    $this->assertSame('Hello, world', $options['json']['messages'][1]['content']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => 'Привет, мир']]],
            ])));

        $result = $client->request('Translate to Russian:', 'Hello, world');
        $this->assertSame('Привет, мир', $result);
    }

    public function testRequestDoesNotSendMaxTokensByDefault(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient(
            apiKey: 'sk-test',
            httpClient: $httpClient,
            apiUrl: 'https://x',
            model: 'm',
        );

        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://x',
                $this->callback(function (array $options): bool {
                    $this->assertArrayNotHasKey('max_tokens', $options['json']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $client->request('s', 'u');
    }

    public function testRequestSendsMaxTokensWhenConfigured(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient(
            apiKey: 'sk-test',
            httpClient: $httpClient,
            apiUrl: 'https://x',
            model: 'm',
            temperature: 0.3,
            usageObserver: null,
            maxOutputTokens: 12000,
        );

        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://x',
                $this->callback(function (array $options): bool {
                    $this->assertSame(12000, $options['json']['max_tokens']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $client->request('s', 'u');
    }

    public function testConstructorRejectsNonPositiveMaxOutputTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OpenAICompatibleClient(
            apiKey: 'k',
            httpClient: $this->createMock(Client::class),
            apiUrl: 'https://x',
            model: 'm',
            maxOutputTokens: 0,
        );
    }

    public function testRequestSendsCustomTemperatureFromConstructor(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient(
            apiKey: 'sk-test',
            httpClient: $httpClient,
            apiUrl: 'https://x',
            model: 'm',
            temperature: 0.7,
        );

        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://x',
                $this->callback(function (array $options): bool {
                    $this->assertSame(0.7, $options['json']['temperature']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));

        $client->request('s', 'u');
    }

    public function testRequestReturnsChapterXhtmlResponseAsIsWithoutBodyHandling(): void
    {
        $httpClient = $this->createMock(Client::class);
        $client = new OpenAICompatibleClient(
            apiKey: 'sk-test',
            httpClient: $httpClient,
            apiUrl: 'https://x',
            model: 'm',
        );

        $chapterXhtml = <<<'XHTML'
            <?xml version="1.0" encoding="utf-8"?>
            <!DOCTYPE html>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
              <head><title>Chapter 1</title></head>
              <body epub:type="bodymatter chapter">
                <h1>Chapter 1</h1>
                <p>It was a bright cold day in April.</p>
              </body>
            </html>
            XHTML;

        $translatedXhtml = <<<'XHTML'
            <?xml version="1.0" encoding="utf-8"?>
            <!DOCTYPE html>
            <html xmlns="http://www.w3.org/1999/xhtml" xml:lang="ru" lang="ru">
              <head><title>Глава 1</title></head>
              <body epub:type="bodymatter chapter">
                <h1>Глава 1</h1>
                <p>Был ясный холодный апрельский день.</p>
              </body>
            </html>
            XHTML;

        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function (array $options) use ($chapterXhtml): bool {
                    $this->assertSame($chapterXhtml, $options['json']['messages'][1]['content']);

                    return true;
                }),
            )
            ->willReturn(new Response(200, [], (string) json_encode([
                'choices' => [['message' => ['content' => $translatedXhtml]]],
            ])));

        $result = $client->request('System', $chapterXhtml);

        $this->assertSame($translatedXhtml, $result);
    }
}
