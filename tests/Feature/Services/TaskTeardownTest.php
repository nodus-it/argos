<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\Demo\DemoDeployer;
use App\Services\Task\TaskTeardown;
use App\Services\Workflow\RunResourceReaper;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class TaskTeardownTest extends TestCase
{
    public function test_purge_reaps_run_resources_demo_and_volume(): void
    {
        Process::fake();

        $reaper = $this->mock(RunResourceReaper::class);
        $reaper->shouldReceive('reapTask')->once()->with('T1');

        $demo = $this->mock(DemoDeployer::class);
        $demo->shouldReceive('teardownBySlug')->once()->with('demo-my-task');

        app(TaskTeardown::class)->purge('T1', 'task_ws_my_task', 'demo-my-task');

        Process::assertRan(fn ($p): bool => str_contains(
            is_array($p->command) ? implode(' ', $p->command) : (string) $p->command,
            'volume rm task_ws_my_task',
        ));
    }

    public function test_remove_volume_only_drops_the_volume(): void
    {
        Process::fake();

        // No reaper / demo interaction on the completed-task path.
        $this->mock(RunResourceReaper::class)->shouldNotReceive('reapTask');
        $this->mock(DemoDeployer::class)->shouldNotReceive('teardownBySlug');

        app(TaskTeardown::class)->removeVolume('task_ws_done');

        Process::assertRan(fn ($p): bool => str_contains(
            is_array($p->command) ? implode(' ', $p->command) : (string) $p->command,
            'volume rm task_ws_done',
        ));
    }
}
