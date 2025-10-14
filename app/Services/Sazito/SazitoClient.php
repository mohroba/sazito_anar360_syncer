<?php

declare(strict_types=1);

namespace App\Services\Sazito;

use App\Services\Http\HttpClientFactory;
use App\Services\Sazito\Exceptions\SazitoRequestException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class SazitoClient
{
    private const DRIVER = 'SAZITO';

    private ClientInterface $client;

    public function __construct(
        HttpClientFactory $httpClientFactory,
        private readonly array $config,
    ) {
        $this->client = $httpClientFactory->make(
            $this->config['base_uri'],
            self::DRIVER,
            [
                'X-API-KEY' => $this->config['api_key'],
                'Accept' => 'application/json',
            ],
        );
    }

    /**
     * @throws SazitoRequestException
     */
    public function putPrice(string $variantId, int $price, ?int $discountPrice = null, ?bool $hasRawPrice = null, array $options = []): array
    {
        $body = ['price' => $price];
        if ($hasRawPrice !== null) {
            $body['has_raw_price'] = $hasRawPrice;
        }
        if ($discountPrice !== null) {
            $body['discount_price'] = $discountPrice;
        }

        return $this->send('PUT', sprintf('/accounting/update-price/%s', $variantId), $body, $options);
    }

    /**
     * @throws SazitoRequestException
     */
    public function putStock(string $variantId, int $stock, bool $isRelative = false, array $options = []): array
    {
        $body = [
            'is_stock_manager' => true,
            'stock_number' => $stock,
        ];

        if ($isRelative) {
            $body['is_relative'] = true;
        }

        return $this->send('PUT', sprintf('/accounting/update-stock/%s', $variantId), $body, $options);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $options
     *
     * @throws SazitoRequestException
     */
    private function send(string $method, string $uri, array $body, array $options): array
    {
        try {
            $response = $this->client->request($method, $uri, [
                'json' => $body,
                ...$options,
            ]);
        } catch (GuzzleException $exception) {
            throw new SazitoRequestException(0, null, $exception->getMessage(), $exception);
        }

        return $this->validateResponse($uri, $response);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SazitoRequestException
     */
    private function validateResponse(string $uri, ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status === 409) {
            return $decoded;
        }

        if ($status >= 400) {
            throw new SazitoRequestException(
                $status,
                $decoded,
                sprintf('Sazito responded with status %d for %s', $status, $uri)
            );
        }

        return $decoded;
    }
}
