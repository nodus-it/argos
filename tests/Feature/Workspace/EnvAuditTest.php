<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\TaskKind;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Workspace\Environment\EnvAudit;
use App\Services\Workspace\WorkspacePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EnvAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> path => contents in the repo */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $git = Mockery::mock(GitServiceContract::class);
        $git->shouldReceive('getFileContents')->andReturnUsing(fn (string $repo, string $path, string $ref): ?string => $this->files[$path] ?? null);
        $this->mock(GitServiceFactory::class)->shouldReceive('forWorkspace')->andReturn($git);
    }

    public function test_parse_reads_values_quotes_and_comments(): void
    {
        $this->assertSame(
            ['APP_NAME' => 'Nexus App', 'MAIL_PASSWORD' => '', 'STRIPE_SECRET' => '', 'QUEUE_CONNECTION' => 'sync'],
            EnvAudit::parse("# comment\nAPP_NAME=\"Nexus App\"\nMAIL_PASSWORD=\nexport STRIPE_SECRET= # set me\nQUEUE_CONNECTION=sync\n"),
        );
    }

    public function test_open_and_missing_keys_skip_what_argos_the_example_or_the_workspace_fill(): void
    {
        $this->files = [
            '.env.example' => "APP_KEY=\nDB_PASSWORD=\nQUEUE_CONNECTION=sync\nMAIL_PASSWORD=\nSTRIPE_SECRET=\nOPENAI_KEY=\n",
            '.argos/demo.yml' => "env:\n  required: [STRIPE_SECRET, OPENAI_KEY]\nlogin:\n  email: admin@demo.test\n  password: secret\n",
        ];
        $workspace = Workspace::factory()->create(['worker_env' => [['name' => 'OPENAI_KEY', 'value' => 'sk-1']]]);

        $audit = app(EnvAudit::class);

        $this->assertSame(['open' => ['MAIL_PASSWORD', 'STRIPE_SECRET'], 'missing' => ['STRIPE_SECRET']], $audit->audit($workspace));
        $this->assertSame(['path' => '/login', 'email' => 'admin@demo.test', 'password' => 'secret'], $audit->login($workspace));
    }

    public function test_the_database_driver_follows_the_repo_when_the_sidecar_speaks_it(): void
    {
        $this->files = ['.env.example' => "DB_CONNECTION=mariadb\n"];
        $this->assertSame('mariadb', app(EnvAudit::class)->databaseDriver(Workspace::factory()->create()));

        $this->files = ['.env.example' => "DB_CONNECTION=sqlite\n"];
        $this->assertSame('mysql', app(EnvAudit::class)->databaseDriver(Workspace::factory()->create()));
    }

    public function test_missing_required_keys_lock_previews_except_for_the_setup_task(): void
    {
        config(['argos.preview.enabled' => true]);
        $this->files = ['.env.example' => "STRIPE_SECRET=\n", '.argos/demo.yml' => "env:\n  required: [STRIPE_SECRET]\n"];
        $workspace = Workspace::factory()->create(['live_demo_enabled' => true]);

        $this->assertSame(
            __('workspaces.preview.missing_env', ['keys' => 'STRIPE_SECRET']),
            app(WorkspacePreviewService::class)->unavailableReason(Task::factory()->create(['workspace_id' => $workspace->id])),
        );
        $this->assertNull(app(WorkspacePreviewService::class)->unavailableReason(Task::factory()->create(['workspace_id' => $workspace->id, 'kind' => TaskKind::Setup])));
    }
}
