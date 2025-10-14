<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\CacheManager;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';

    private const STATE_OPEN = 'open';

    private const STATE_HALF_OPEN = 'half-open';

    public function __construct(
        private readonly CacheManager $cache,
        private readonly int $failureThreshold = 5,
        private readonly int $evaluationSeconds = 60,
        private readonly int $openSeconds = 45,
    ) {}

    public function recordSuccess(string $key): void
    {
        $bucket = $this->bucketKey($key);
        $this->cache->forget($bucket);
        $this->cache->put($this->stateKey($key), self::STATE_CLOSED, $this->evaluationSeconds);
    }

    public function recordFailure(string $key): void
    {
        $bucket = $this->bucketKey($key);
        $failures = (int) $this->cache->increment($bucket);
        $this->cache->put($bucket, $failures, $this->evaluationSeconds);

        if ($failures >= $this->failureThreshold) {
            $this->cache->put($this->stateKey($key), self::STATE_OPEN, $this->openSeconds);
        }
    }

    public function isOpen(string $key): bool
    {
        return $this->cache->get($this->stateKey($key)) === self::STATE_OPEN;
    }

    public function transitionHalfOpen(string $key): void
    {
        $this->cache->put($this->stateKey($key), self::STATE_HALF_OPEN, $this->evaluationSeconds);
    }

    private function bucketKey(string $key): string
    {
        return sprintf('circuit:%s:failures', $key);
    }

    private function stateKey(string $key): string
    {
        return sprintf('circuit:%s:state', $key);
    }
}
