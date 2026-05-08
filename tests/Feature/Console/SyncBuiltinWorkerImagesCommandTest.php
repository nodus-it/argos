<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncBuiltinWorkerImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_seeds_repo_built_ins(): void
    {
        $this->artisan('argos:sync-builtin-images')->assertSuccessful();

        $this->assertGreaterThan(0, WorkerStack::query()->where('is_builtin', true)->count());
    }

    public function test_command_is_idempotent_on_repeated_runs(): void
    {
        $this->artisan('argos:sync-builtin-images')->assertSuccessful();
        $firstStackCount = WorkerStack::query()->count();

        $this->artisan('argos:sync-builtin-images')->assertSuccessful();

        $this->assertSame($firstStackCount, WorkerStack::query()->count());
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->artisan('argos:sync-builtin-images', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        $this->assertSame(0, WorkerStack::query()->count());
    }
}
