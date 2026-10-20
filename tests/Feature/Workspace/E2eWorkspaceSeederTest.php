<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\E2eWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2eWorkspaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_pairing_workspace_for_two_users(): void
    {
        $this->seed(E2eWorkspaceSeeder::class);

        $workspace = Workspace::query()->where('slug', 'e2e-pairing')->firstOrFail();
        $this->assertSame(WorkspaceRole::Owner, $workspace->roleOf(User::query()->where('email', 'admin@argos.local')->firstOrFail()));
        $this->assertSame(WorkspaceRole::Editor, $workspace->roleOf(User::query()->where('email', 'pair@argos.local')->firstOrFail()));
        $this->assertSame(1, Thread::query()->where('workspace_id', $workspace->id)->count());
    }
}
