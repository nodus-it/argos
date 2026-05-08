<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\WorkerImageBuildResource;
use App\Filament\Admin\Resources\WorkerImageBuildResource\Pages\ListWorkerImageBuilds;
use App\Filament\Admin\Resources\WorkerImageBuildResource\Pages\ViewWorkerImageBuild;
use App\Jobs\BuildWorkerImageJob;
use App\Models\User;
use App\Models\WorkerImageBuild;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkerImageBuildResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_create_is_disabled(): void
    {
        $this->assertFalse(WorkerImageBuildResource::canCreate());
    }

    public function test_list_renders_existing_builds(): void
    {
        WorkerImageBuild::factory()->count(3)->create();

        Livewire::test(ListWorkerImageBuilds::class)
            ->assertSuccessful();
    }

    public function test_view_page_renders(): void
    {
        $build = WorkerImageBuild::factory()->ready()->create();

        Livewire::test(ViewWorkerImageBuild::class, ['record' => $build->getKey()])
            ->assertSuccessful();
    }

    public function test_rebuild_action_dispatches_build_job(): void
    {
        Queue::fake();
        $build = WorkerImageBuild::factory()->ready()->create();

        Livewire::test(ListWorkerImageBuilds::class)
            ->callAction(TestAction::make('rebuild')->table($build))
            ->assertNotified();

        Queue::assertPushed(BuildWorkerImageJob::class, function (BuildWorkerImageJob $job) use ($build): bool {
            return $job->workerStackId === $build->worker_stack_id
                && $job->agentName === $build->agent_name;
        });
    }
}
