<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Actions\Sync\BackoffPolicy;
use App\Actions\Sync\RecordExternalRequestAction;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class HttpClientFactory
{
    /** @var array<string, ClientInterface> */
    private array $clients = [];

    public function __construct(
        private readonly RecordExternalRequestAction $recordExternalRequest,
        private readonly BackoffPolicy $backoffPolicy,
        private readonly int $timeout,
        private readonly int $maxRetries,
    ) {}

    public function make(string $baseUri, string $driver, array $defaultHeaders = []): ClientInterface
    {
        if (isset($this->clients[$driver])) {
            return $this->clients[$driver];
        }

        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response, ?Throwable $exception): bool {
                if ($retries >= $this->maxRetries) {
                    return false;
                }

                if ($response !== null) {
                    $status = $response->getStatusCode();
                    if ($status >= 500 || $status === 429) {
                        return true;
                    }

                    return false;
                }

                return $exception !== null;
            },
            function (int $retries): int {
                return $this->backoffPolicy->calculate($retries + 1);
            }
        ));

        $stack->push(function (callable $handler) use ($driver) {
            return function (RequestInterface $request, array $options) use ($handler, $driver) {
                $attempt = ($options['__attempt'] ?? 0) + 1;
                $options['__attempt'] = $attempt;
                $start = microtime(true);

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $options, $driver, $attempt, $start) {
                        $this->recordAttempt($driver, $request, $options, $response, null, $attempt, $start);

                        return $response;
                    },
                    function (Throwable $exception) use ($request, $options, $driver, $attempt, $start) {
                        $this->recordAttempt($driver, $request, $options, null, $exception, $attempt, $start);

                        throw $exception;
                    }
                );
            };
        });

        $config = [
            'base_uri' => $baseUri,
            'timeout' => $this->timeout,
            'http_errors' => false,
            'allow_redirects' => false,
            'handler' => $stack,
        ];

        if ($defaultHeaders !== []) {
            $config['headers'] = $defaultHeaders;
        }

        return $this->clients[$driver] = new Client($config);
    }

    private function recordAttempt(
        string $driver,
        RequestInterface $request,
        array $options,
        ?ResponseInterface $response,
        ?Throwable $exception,
        int $attempt,
        float $startTime,
    ): void {
        $duration = (int) round((microtime(true) - $startTime) * 1000);

        $reqBody = null;
        if ($request->getBody()->isSeekable()) {
            $request->getBody()->rewind();
        }
        $reqBodyString = (string) $request->getBody();
        if ($reqBodyString !== '') {
            $reqBody = ['raw' => $this->truncate($this->sanitizeBody($reqBodyString))];
        }

        $respBody = null;
        $status = null;
        if ($response !== null) {
            $status = $response->getStatusCode();
            $respBody = $this->truncate($this->sanitizeBody((string) $response->getBody()));
        }

        $outcome = 'success';
        if ($exception !== null) {
            $outcome = str_contains($exception::class, 'Connect') ? 'timeout' : 'fail';
        } elseif ($status !== null && $status >= 500) {
            $outcome = 'retry';
        }

        $this->recordExternalRequest->execute([
            'driver' => $driver,
            'method' => $request->getMethod(),
            'url' => (string) $request->getUri(),
            'query' => $options['query'] ?? null,
            'req_headers' => $this->sanitizeHeaders($request->getHeaders()),
            'req_body' => $reqBody,
            'resp_status' => $status,
            'resp_headers' => $response ? $this->sanitizeHeaders($response->getHeaders()) : null,
            'resp_body' => $respBody ? ['raw' => $respBody] : null,
            'duration_ms' => $duration,
            'attempt' => $attempt,
            'outcome' => $outcome,
            'run_id' => $options['run_id'] ?? null,
            'idempotency_key' => $options['idempotency_key'] ?? null,
        ]);
    }

    private function truncate(string $value): string
    {
        $limit = 65_536;

        return strlen($value) > $limit ? substr($value, 0, $limit) : $value;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, array<int, string>>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'x-api-key', 'set-cookie'];

        foreach ($headers as $name => &$values) {
            if (in_array(strtolower($name), $sensitive, true)) {
                $values = array_fill(0, count($values), '***');
            }
        }

        unset($values);

        return $headers;
    }

    private function sanitizeBody(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return $trimmed;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $sanitized = $this->maskSensitiveValues($decoded);
            $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : $trimmed;
        }

        return preg_replace(
            '/("?(?:transfereeMobile|transFereeMobile|mobile|phone)"?\s*:\s*")([^"\\]*)("?)/i',
            '$1***$3',
            $trimmed
        ) ?? $trimmed;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function maskSensitiveValues(array $data): array
    {
        $sensitiveKeys = ['transfereemobile', 'transfreemobile', 'mobile', 'phone'];

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->maskSensitiveValues($value);

                continue;
            }

            if (is_string($value) && in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '***';
            }
        }

        return $data;
    }
}
