<?php

declare(strict_types=1);

namespace App\Actions\Sync;

class BackoffPolicy
{
    public function __construct(
        private readonly int $baseMilliseconds = 500,
        private readonly int $maxMilliseconds = 30_000,
    ) {}

    public function calculate(int $attempt): int
    {
        $attempt = max($attempt, 1);
        $delay = $this->baseMilliseconds * (2 ** ($attempt - 1));
        $delay = min($delay, $this->maxMilliseconds);

        $jitter = random_int(0, (int) round($delay * 0.25));

        return min((int) ($delay + $jitter), $this->maxMilliseconds);
    }
}
