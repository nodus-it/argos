<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\ModelCatalog;
use App\Services\Workspace\ThreadEventIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_session_the_aliases_are_offered(): void
    {
        $options = app(ModelCatalog::class)->options(AgentCredential::factory()->create());

        $this->assertSame(['sonnet', 'opus', 'haiku'], array_column($options, 'value'));
    }

    public function test_the_models_a_session_reports_replace_the_aliases_for_that_agent(): void
    {
        $workspace = Workspace::factory()->create();
        $agent = AgentCredential::factory()->create();
        $thread = Thread::factory()->create(['workspace_id' => $workspace->id, 'agent_credential_id' => $agent->id]);

        app(ThreadEventIngestor::class)->ingest($workspace, [[
            'thread_id' => $thread->id, 'seq' => 1, 'type' => 'session.started',
            'data' => ['claude_session_id' => 's1', 'models' => [
                ['value' => 'default', 'display_name' => 'Default (recommended)', 'description' => 'Sonnet 5.5'],
                ['value' => 'opus', 'display_name' => 'Opus', 'description' => 'Opus 5.5'],
                ['value' => 'rm -rf /', 'display_name' => 'evil'],
            ]],
        ]]);

        $catalog = app(ModelCatalog::class);
        $this->assertSame(['default', 'opus'], array_column($catalog->options($agent), 'value'));
        // Other agents (subscriptions) are not affected.
        $this->assertSame(['sonnet', 'opus', 'haiku'], array_column($catalog->options(AgentCredential::factory()->create()), 'value'));
        // A thread still on an old id keeps it selectable.
        $this->assertContains('claude-sonnet-4-6', array_column($catalog->options($agent, 'claude-sonnet-4-6'), 'value'));
    }

    public function test_model_ids_are_validated(): void
    {
        foreach (['opus', 'claude-sonnet-5-5', 'sonnet[1m]', 'claude-haiku-4-5-20251001'] as $ok) {
            $this->assertTrue(ModelCatalog::isValidId($ok), $ok);
        }
        foreach (['', 'Opus', 'a b', '--model', str_repeat('a', 100)] as $bad) {
            $this->assertFalse(ModelCatalog::isValidId($bad), $bad);
        }
    }
}
