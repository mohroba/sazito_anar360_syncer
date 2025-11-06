<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use App\Actions\Sync\BuildAnar360TaxonomyAction;
use App\Actions\Sync\RecordEventAction;
use App\Models\SyncRun;
use App\Services\Anar360\Anar360Client;
use Domain\DTO\AttributeDTO;
use Domain\DTO\CategoryDTO;
use Mockery;
use Tests\TestCase;

class BuildAnar360TaxonomyActionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_execute_returns_maps_and_records_event(): void
    {
        $client = Mockery::mock(Anar360Client::class);
        $recordEvent = Mockery::mock(RecordEventAction::class);

        $run = SyncRun::make(['id' => 'run-1']);

        $client->shouldReceive('fetchCategories')
            ->once()
            ->with(1, 25, 'run-1')
            ->andReturn([
                'items' => [
                    new CategoryDTO('cat-1', 'Cat One', ['attr-1'], null, ['Cat One'], []),
                ],
                'meta' => ['total' => 1],
            ]);

        $client->shouldReceive('fetchAttributes')
            ->once()
            ->with(1, 25, 'run-1')
            ->andReturn([
                'items' => [
                    new AttributeDTO('attr-1', 'Attribute One', ['A'], []),
                ],
                'meta' => ['total' => 1],
            ]);

        $recordEvent->shouldReceive('execute')
            ->once()
            ->with('run-1', 'ANAR360_TAXONOMY_FETCHED', Mockery::subset([
                'category_count' => 1,
                'attribute_count' => 1,
            ]));

        $action = new BuildAnar360TaxonomyAction($client, $recordEvent);

        $result = $action->execute($run, 1, 25);

        $this->assertArrayHasKey('categories', $result);
        $this->assertArrayHasKey('attributes', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertSame(1, count($result['categories']));
        $this->assertSame('Cat One', $result['categories']['cat-1']->name);
        $this->assertSame('Attribute One', $result['attributes']['attr-1']->name);
        $this->assertSame(['total' => 1], $result['meta']['categories']);
    }
}
