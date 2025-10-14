<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\Sync\BackoffPolicy;
use PHPUnit\Framework\TestCase;

class BackoffPolicyTest extends TestCase
{
    public function test_backoff_never_below_base(): void
    {
        $policy = new BackoffPolicy(500, 5_000);
        $first = $policy->calculate(1);

        $this->assertGreaterThanOrEqual(500, $first);
        $this->assertLessThanOrEqual(5_000, $first);
    }

    public function test_backoff_caps_at_max(): void
    {
        $policy = new BackoffPolicy(500, 5_000);
        $value = $policy->calculate(10);

        $this->assertLessThanOrEqual(5_000, $value);
    }
}
