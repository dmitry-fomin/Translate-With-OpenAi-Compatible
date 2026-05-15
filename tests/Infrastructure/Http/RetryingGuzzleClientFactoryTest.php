<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\RetryingGuzzleClientFactory;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class RetryingGuzzleClientFactoryTest extends TestCase
{
    public function testRetriesOn429UntilSuccess(): void
    {
        $mock = new MockHandler([
            new Response(429, [], 'rate limited 1'),
            new Response(429, [], 'rate limited 2'),
            new Response(200, [], 'ok'),
        ]);

        $client = RetryingGuzzleClientFactory::create(
            maxRetries: 5,
            baseDelayMs: 0,
            requestTimeoutSec: 30,
            connectTimeoutSec: 5,
            handler: HandlerStack::create($mock),
        );

        $response = $client->post('https://example.test/v1/chat', [
            'json' => ['hello' => 'world'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
        $this->assertCount(0, $mock); // вся очередь использована
    }

    public function testThrowsAfterMaxRetriesExhaustedOn5xx(): void
    {
        $mock = new MockHandler([
            new Response(500, [], 'boom 1'),
            new Response(500, [], 'boom 2'),
            new Response(500, [], 'boom 3'),
            new Response(500, [], 'boom 4'),
            new Response(500, [], 'boom 5'),
            new Response(500, [], 'boom 6'),
        ]);

        $client = RetryingGuzzleClientFactory::create(
            maxRetries: 2,
            baseDelayMs: 0,
            requestTimeoutSec: 30,
            connectTimeoutSec: 5,
            handler: HandlerStack::create($mock),
        );

        $this->expectException(ServerException::class);

        try {
            $client->post('https://example.test/v1/chat', [
                'json' => ['hello' => 'world'],
            ]);
        } finally {
            // 1 первичный запрос + 2 retry = 3 запроса
            $this->assertSame(6 - 3, $mock->count());
        }
    }

    public function testTimeoutsAreSetOnClient(): void
    {
        $client = RetryingGuzzleClientFactory::create(
            maxRetries: 1,
            baseDelayMs: 0,
            requestTimeoutSec: 600,
            connectTimeoutSec: 60,
        );

        $this->assertSame(600, $client->getConfig('timeout'));
        $this->assertSame(60, $client->getConfig('connect_timeout'));
    }

    public function testNoRetriesOnImmediateSuccess(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'first-try'),
            new Response(500, [], 'should not be used'),
        ]);

        $client = RetryingGuzzleClientFactory::create(
            maxRetries: 5,
            baseDelayMs: 0,
            requestTimeoutSec: 30,
            connectTimeoutSec: 5,
            handler: HandlerStack::create($mock),
        );

        $response = $client->post('https://example.test/v1/chat', [
            'json' => ['hello' => 'world'],
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('first-try', (string) $response->getBody());
        // должен остаться неиспользованный 500
        $this->assertSame(1, $mock->count());
    }
}
