<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('array')->clear();
    }

    public function test_circuit_opens_after_failures(): void
    {
        $breaker = app(CircuitBreaker::class);

        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure('sazito');
        }

        $this->assertTrue($breaker->isOpen('sazito'));
    }

    public function test_circuit_resets_on_success(): void
    {
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure('sazito');
        $breaker->recordSuccess('sazito');

        $this->assertFalse($breaker->isOpen('sazito'));
    }
}
