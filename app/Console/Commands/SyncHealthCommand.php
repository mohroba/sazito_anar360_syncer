<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Failure;
use App\Models\SyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncHealthCommand extends Command
{
    protected $signature = 'sync:health';

    protected $description = 'Show integration health information';

    public function handle(): int
    {
        $latestRun = SyncRun::query()->latest('created_at')->first();
        $failureCount = Failure::query()->count();

        $this->line('Integration Health Report');
        $this->line('--------------------------');

        if ($latestRun) {
            $this->table(['Run ID', 'Status', 'Scope', 'Started', 'Finished'], [[
                $latestRun->id,
                $latestRun->status,
                $latestRun->scope,
                optional($latestRun->started_at)->toDateTimeString(),
                optional($latestRun->finished_at)->toDateTimeString(),
            ]]);
        } else {
            $this->warn('No runs recorded.');
        }

        $this->info(sprintf('Pending failures: %d', $failureCount));

        $circuitStates = [
            'sazito' => Cache::get('circuit:sazito:state', 'closed'),
            'anar360' => Cache::get('circuit:anar360:state', 'closed'),
        ];

        foreach ($circuitStates as $service => $state) {
            $this->line(sprintf('Circuit %s: %s', $service, $state));
        }

        return self::SUCCESS;
    }
}
