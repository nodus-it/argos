<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Demo\DemoWorkspaceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DemoWorkspaceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_when_task_has_no_feature_branch(): void
    {
        Process::fake();
        $task = Task::factory()->create(['feature_branch' => null]);

        app(DemoWorkspaceSync::class)->syncToRemote($task);

        Process::assertNothingRan();
    }

    public function test_runs_git_sync_against_the_volume_when_branch_set(): void
    {
        Process::fake();
        $profile = RepoProfile::factory()->create();
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'feature_branch' => 'feat/x',
        ]);

        app(DemoWorkspaceSync::class)->syncToRemote($task);

        Process::assertRan(function ($process): bool {
            $cmd = implode(' ', (array) $process->command);

            return str_contains($cmd, 'docker')
                && str_contains($cmd, 'alpine/git')
                && str_contains($cmd, 'feat/x')
                && str_contains($cmd, 'fetch')
                && str_contains($cmd, 'status --porcelain'); // dirty-tree guard present
        });
    }
}
