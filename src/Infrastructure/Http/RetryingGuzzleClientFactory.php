<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryingGuzzleClientFactory
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /**
     * Собирает Guzzle-клиента с middleware ретраев на 429/5xx + connect-ошибки и явными таймаутами.
     *
     * @param int               $maxRetries        максимум повторов (без учёта первичной попытки).
     * @param int               $baseDelayMs       базовая задержка для экспоненциального бэкоффа в миллисекундах.
     * @param int               $requestTimeoutSec общий тайм-аут HTTP-запроса, секунды.
     * @param int               $connectTimeoutSec тайм-аут TCP-коннекта, секунды.
     * @param HandlerStack|null $handler           внешний HandlerStack (для тестов через MockHandler).
     */
    public static function create(
        int $maxRetries,
        int $baseDelayMs,
        int $requestTimeoutSec,
        int $connectTimeoutSec,
        ?HandlerStack $handler = null,
    ): Client {
        $stack = $handler ?? HandlerStack::create();

        $stack->push(Middleware::retry(
            static function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?\Throwable $exception = null,
            ) use ($maxRetries): bool {
                if ($retries >= $maxRetries) {
                    return false;
                }

                if ($exception instanceof ConnectException) {
                    return true;
                }

                if ($response !== null && in_array($response->getStatusCode(), self::RETRYABLE_STATUSES, true)) {
                    return true;
                }

                return false;
            },
            static function (int $retries) use ($baseDelayMs): int {
                return $baseDelayMs * (2 ** $retries);
            },
        ));

        return new Client([
            'handler' => $stack,
            RequestOptions::TIMEOUT => $requestTimeoutSec,
            RequestOptions::CONNECT_TIMEOUT => $connectTimeoutSec,
        ]);
    }
}
