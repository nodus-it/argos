<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ExternalIssueLink;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestTaskProvidersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Run the simulate webhook job inline so the offline webhook check is
        // deterministic regardless of the configured queue driver.
        config(['queue.default' => 'sync']);
    }

    public function test_it_seeds_bindings_and_runs_the_offline_webhook_check(): void
    {
        User::factory()->create();

        // No OAuth accounts connected → decline the re-check for each provider,
        // so the real poll / write-back steps are never reached (offline run).
        $this->artisan('test:task-providers')
            ->expectsConfirmation('  Connected the GitHub account and want to re-check?', 'no')
            ->expectsConfirmation('  Connected the GitLab account and want to re-check?', 'no')
            ->expectsConfirmation('  Connected the Linear account and want to re-check?', 'no')
            ->assertSuccessful();

        // Setup seeded the full demo matrix.
        $this->assertSame(3, RepoProfile::where('name', 'like', 'provider-demo%')->count());

        // The webhook check ran for all three providers: each matching-label
        // issue imported a Task, each non-matching one was filtered (link only).
        $this->assertSame(3, Task::count());
        $this->assertSame(3, ExternalIssueLink::whereNotNull('task_id')->count());
        $this->assertSame(3, ExternalIssueLink::whereNull('task_id')->count());
    }

    public function test_it_fails_without_a_user(): void
    {
        $this->artisan('test:task-providers')->assertFailed();
    }
}
