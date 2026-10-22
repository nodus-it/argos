<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ModelCatalog;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
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
        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'agent_credential_id' => $agent->id]);

        app(TaskEventIngestor::class)->ingest($workspace, [[
            'task_id' => $task->id, 'seq' => 1, 'type' => 'session.started',
            'data' => ['claude_session_id' => 's1', 'models' => [
                ['value' => 'default', 'display_name' => 'Default (recommended)', 'description' => 'Everyday tasks', 'resolved_model' => 'claude-sonnet-5-5'],
                ['value' => 'opus', 'display_name' => 'Opus', 'description' => 'Most capable', 'resolved_model' => 'claude-opus-5-5'],
                ['value' => 'sonnet[1m]', 'display_name' => 'Sonnet (1M context)', 'description' => 'Long sessions', 'resolved_model' => 'claude-sonnet-5-5[1m]'],
                ['value' => 'rm -rf /', 'display_name' => 'evil'],
            ]],
        ]]);

        // Kept in the database: survives a cache clear.
        Cache::flush();
        $agent->refresh();
        $catalog = app(ModelCatalog::class);
        $options = $catalog->options($agent);
        // Like Claude Code: the aliases (always the newest) plus the fixed versions they resolve to today.
        $this->assertSame(['default', 'opus', 'sonnet[1m]', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-sonnet-5-5[1m]'], array_column($options, 'value'));
        $this->assertSame(['latest', 'latest', 'latest', 'pinned', 'pinned', 'pinned'], array_column($options, 'group'));
        $this->assertSame(['Default (recommended) · Sonnet 5.5', 'Opus · Opus 5.5', 'Sonnet (1M context) · Sonnet 5.5 (1M)', 'Sonnet 5.5', 'Opus 5.5', 'Sonnet 5.5 (1M)'], array_column($options, 'label'));
        // Other agents (subscriptions) are not affected.
        $this->assertSame(['sonnet', 'opus', 'haiku'], array_column($catalog->options(AgentCredential::factory()->create()), 'value'));
        // A task still on an old id keeps it selectable.
        $old = collect($catalog->options($agent, 'claude-sonnet-4-6'))->firstWhere('value', 'claude-sonnet-4-6');
        $this->assertSame(['label' => 'Sonnet 4.6', 'group' => 'other'], ['label' => $old['label'], 'group' => $old['group']]);
    }

    public function test_versions_get_readable_names(): void
    {
        $this->assertSame('Opus 5.5', ModelCatalog::versionLabel('claude-opus-5-5'));
        $this->assertSame('Haiku 4.5', ModelCatalog::versionLabel('claude-haiku-4-5-20251001'));
        $this->assertSame('Sonnet 5.5 (1M)', ModelCatalog::versionLabel('claude-sonnet-5-5[1m]'));
        $this->assertSame('Opus 5', ModelCatalog::versionLabel('claude-opus-5'));
        $this->assertNull(ModelCatalog::versionLabel('opus'));
    }

    public function test_any_valid_model_id_can_be_entered_by_hand(): void
    {
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $owner = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => WorkspaceRole::Member]);
        $agent = AgentCredential::factory()->ownedBy($owner)->create();
        $task = Task::factory()->ownedBy($owner)->create(['workspace_id' => $workspace->id, 'agent_credential_id' => $agent->id, 'model' => 'sonnet']);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Event::fake([TaskStream::class]);

        $chat = Livewire::withQueryParams(['task' => $task->id])->actingAs($owner)
            ->test(TaskChat::class, ['workspaceId' => $workspace->id])
            ->assertSee(__('workspaces.models.custom'))
            ->assertSee(__('workspaces.models.group_latest'));

        $chat->call('setModel', 'rm -rf')->assertNotified(__('workspaces.models.invalid'));
        $this->assertSame('sonnet', $task->fresh()->model);

        $chat->call('setModel', 'claude-opus-5');
        $this->assertSame('claude-opus-5', $task->fresh()->model);
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

    public function test_the_composer_asks_for_the_models_once_without_sending_a_message(): void
    {
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $owner = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => WorkspaceRole::Member]);
        $agent = AgentCredential::factory()->ownedBy($owner)->create(['credentials' => ['token' => 'oat-a']]);
        $task = Task::factory()->ownedBy($owner)->create(['workspace_id' => $workspace->id, 'agent_credential_id' => $agent->id, 'model' => 'sonnet']);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Event::fake([TaskStream::class]);

        $chat = Livewire::withQueryParams(['task' => $task->id])->actingAs($owner)
            ->test(TaskChat::class, ['workspaceId' => $workspace->id])
            ->assertSeeHtml('$wire.refreshModels()')
            ->call('refreshModels')
            ->assertDontSeeHtml('$wire.refreshModels()')
            ->assertSee('Default (recommended) · Sonnet 5.5')
            ->assertSee('Opus 5.5');

        $this->assertSame([$agent->id], FakeWorkspaceDaemon::$modelRequests);
        $this->assertNotNull($agent->fresh()->models_refreshed_at);
        // Nothing went to Claude as a message.
        $this->assertSame(0, TaskEvent::query()->where('task_id', $task->id)->count());

        // Fresh: no second ask; after REFRESH_HOURS it asks again.
        $chat->call('refreshModels');
        $this->assertCount(1, FakeWorkspaceDaemon::$modelRequests);
        $this->travel(ModelCatalog::REFRESH_HOURS + 1)->hours();
        $this->assertTrue(app(ModelCatalog::class)->isStale($agent->fresh()));
    }

    public function test_effort_follows_the_model_and_reaches_the_running_session(): void
    {
        $workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $owner = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => WorkspaceRole::Member]);
        $agent = AgentCredential::factory()->ownedBy($owner)->create(['models_refreshed_at' => now(), 'models' => [
            ['value' => 'opus', 'label' => 'Opus', 'description' => '', 'resolved' => 'claude-opus-5-5', 'effort_levels' => ['low', 'high', 'max']],
            ['value' => 'haiku', 'label' => 'Haiku', 'description' => '', 'resolved' => 'claude-haiku-5-5', 'effort_levels' => []],
        ]]);
        $task = Task::factory()->ownedBy($owner)->create(['workspace_id' => $workspace->id, 'agent_credential_id' => $agent->id, 'model' => 'claude-opus-5-5', 'session_open' => true]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        Event::fake([TaskStream::class]);

        $chat = Livewire::withQueryParams(['task' => $task->id])->actingAs($owner)
            ->test(TaskChat::class, ['workspaceId' => $workspace->id])
            ->assertSee(__('workspaces.models.efforts.max'))
            ->assertDontSee(__('workspaces.models.efforts.medium'))
            ->call('setEffort', 'high');

        $this->assertSame('high', $task->fresh()->effort);
        $this->assertSame([[$task->id, 'high']], FakeWorkspaceDaemon::$efforts);
        $notice = TaskEvent::query()->where('task_id', $task->id)->latest('id')->first();
        $this->assertSame(['effort', 'high'], [$notice->payload['kind'], $notice->payload['effort']]);

        $chat->call('setEffort', 'ultra')->assertNotified(__('workspaces.models.effort_invalid'));
        $chat->call('setEffort', '');
        $this->assertNull($task->fresh()->effort);

        // A model without effort control: no pill.
        $task->forceFill(['model' => 'haiku'])->save();
        $chat->call('$refresh')->assertDontSee(__('workspaces.models.efforts.max'));
    }
}
