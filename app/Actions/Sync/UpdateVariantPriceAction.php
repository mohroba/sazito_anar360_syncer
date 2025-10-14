<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Services\Sazito\Exceptions\SazitoRequestException;
use App\Services\Sazito\SazitoClient;
use Throwable;

class UpdateVariantPriceAction
{
    public function __construct(
        private readonly SazitoClient $client,
        private readonly RecordEventAction $recordEvent,
    ) {}

    public function execute(
        string $runId,
        string $variantId,
        int $price,
        ?int $discountPrice = null,
        ?bool $hasRawPrice = null,
        array $options = [],
    ): array
    {
        try {
            $response = $this->client->putPrice($variantId, $price, $discountPrice, $hasRawPrice, options: [
                ...$options,
                'run_id' => $runId,
            ]);
        } catch (SazitoRequestException $exception) {
            $this->recordEvent->execute($runId, 'VARIANT_PRICE_UPDATED', $this->buildPayload(
                $variantId,
                $price,
                $discountPrice,
                $hasRawPrice,
                [
                    'status' => $exception->statusCode(),
                    'response' => $exception->responseBody(),
                ],
            ), $variantId, 'error');

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordEvent->execute($runId, 'VARIANT_PRICE_UPDATED', $this->buildPayload(
                $variantId,
                $price,
                $discountPrice,
                $hasRawPrice,
                ['error' => $exception->getMessage()],
            ), $variantId, 'error');

            throw $exception;
        }

        $this->recordEvent->execute($runId, 'VARIANT_PRICE_UPDATED', $this->buildPayload(
            $variantId,
            $price,
            $discountPrice,
            $hasRawPrice,
        ), $variantId);

        return $response;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $variantId,
        int $price,
        ?int $discountPrice,
        ?bool $hasRawPrice,
        array $extra = [],
    ): array {
        $payload = [
            'variant_id' => $variantId,
            'price' => $price,
            ...$extra,
        ];

        if ($discountPrice !== null) {
            $payload['discount_price'] = $discountPrice;
        }

        if ($hasRawPrice !== null) {
            $payload['has_raw_price'] = $hasRawPrice;
        }

        return $payload;
    }
}
