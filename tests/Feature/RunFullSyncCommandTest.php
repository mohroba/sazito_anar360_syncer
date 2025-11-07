<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\RunFullSyncCommand;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class RunFullSyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_runs_full_sync_pipeline_when_dependencies_succeed(): void
    {
        Artisan::partialMock()
            ->shouldReceive('call')
            ->once()
            ->with('sync:sazito-products', Mockery::on(function (array $options): bool {
                $this->assertSame(1, $options['--page']);
                $this->assertArrayHasKey('--limit', $options);
                $this->assertSame('true', $options['--all']);

                return true;
            }))
            ->andReturn(SymfonyCommand::SUCCESS)
            ->ordered();

        Artisan::shouldReceive('output')->andReturn('catalogue ok', 'products ok');

        Artisan::shouldReceive('call')
            ->once()
            ->with('sync:products', Mockery::on(function (array $options): bool {
                $this->assertSame(123, $options['--since-ms']);
                $this->assertSame(2, $options['--page']);
                $this->assertSame(10, $options['--limit']);
                $this->assertSame('manual', $options['--run-scope']);

                return true;
            }))
            ->andReturn(SymfonyCommand::SUCCESS)
            ->ordered();

        $command = $this->app->make(RunFullSyncCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--since-ms' => '123',
            '--page' => '2',
            '--limit' => '10',
            '--scope' => 'manual',
            '--catalogue-page' => '1',
            '--catalogue-limit' => '100',
            '--catalogue-all' => 'true',
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $exitCode);
    }

    public function test_bails_out_when_catalogue_sync_fails(): void
    {
        Artisan::partialMock()
            ->shouldReceive('call')
            ->once()
            ->with('sync:sazito-products', Mockery::type('array'))
            ->andReturn(SymfonyCommand::FAILURE);

        Artisan::shouldReceive('output')->once()->andReturn('catalogue failed');

        Artisan::shouldReceive('call')
            ->with('sync:products', Mockery::any())
            ->never();

        $command = $this->app->make(RunFullSyncCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(SymfonyCommand::FAILURE, $exitCode);
    }
}
