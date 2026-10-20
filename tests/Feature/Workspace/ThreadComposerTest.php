<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\PermissionMode;
use App\Enums\ThreadEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\ThreadChat;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Composer extras: image attachments, `@file` search and slash commands. */
class ThreadComposerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Event::fake([ThreadStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Owner, 'Ada');
        AgentCredential::factory()->ownedBy($this->owner)->create();

        $this->chat()->set('newTitle', 'Login')->call('createThread')->assertHasNoErrors();
        $this->thread = Thread::query()->where('title', 'Login')->firstOrFail();
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function chat(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->owner)->test(ThreadChat::class, ['workspaceId' => $this->workspace->id]);
    }

    private function userMessages()
    {
        return ThreadEvent::query()->where('thread_id', $this->thread->id)->where('type', ThreadEventType::UserMessage->value)->get();
    }

    public function test_images_are_stored_and_referenced_without_their_bytes(): void
    {
        $this->chat()->set('threadId', $this->thread->id)
            ->set('uploads', [UploadedFile::fake()->image('shot.png', 40, 40)])
            ->set('draft', 'what is wrong here?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('uploads', []);

        $message = $this->userMessages()->sole();
        $image = $message->payload['images'][0];
        $this->assertSame('image/png', $image['media_type']);
        $this->assertArrayNotHasKey('data', $image);
        Storage::disk('local')->assertExists($image['path']);

        $this->actingAs($this->owner)
            ->get(route('threads.attachments', ['thread' => $this->thread, 'file' => basename($image['path'])]))
            ->assertOk();
    }

    public function test_non_images_are_rejected(): void
    {
        $this->chat()->set('threadId', $this->thread->id)
            ->set('uploads', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->set('draft', 'look')
            ->call('send')
            ->assertHasErrors('uploads.0');

        $this->assertCount(0, $this->userMessages());
    }

    public function test_attachments_are_only_served_to_members(): void
    {
        $this->chat()->set('threadId', $this->thread->id)
            ->set('uploads', [UploadedFile::fake()->image('shot.png')])
            ->call('send');
        $file = basename($this->userMessages()->sole()->payload['images'][0]['path']);
        $url = route('threads.attachments', ['thread' => $this->thread, 'file' => $file]);

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs($this->owner)->get(route('threads.attachments', ['thread' => $this->thread, 'file' => '../secret.png']))->assertNotFound();
    }

    public function test_file_search_uses_the_daemon_index(): void
    {
        $this->assertSame(
            ['app/Http/Controllers/LoginController.php'],
            $this->chat()->instance()->searchFiles('login'),
        );
    }

    public function test_command_list_merges_argos_and_session_commands(): void
    {
        $names = array_column($this->chat()->set('threadId', $this->thread->id)->instance()->commandList(), 'name');

        // The fake session reports `review` too; Argos' own wins, no duplicate.
        $this->assertSame(['plan', 'ship', 'preview', 'review', 'gates', 'checkpoint'], $names);
    }

    public function test_plan_switches_the_mode_and_sends_the_rest(): void
    {
        $this->chat()->set('threadId', $this->thread->id)->set('draft', '/plan refactor login')->call('send')->assertSet('draft', '');

        $this->assertSame(PermissionMode::Plan, $this->thread->fresh()->permission_mode);
        $this->assertSame('refactor login', $this->userMessages()->sole()->payload['text']);
    }

    public function test_ui_commands_are_not_sent_to_claude(): void
    {
        $chat = $this->chat()->set('threadId', $this->thread->id);

        $chat->set('draft', '/ship')->call('send')->assertDispatched('workspace-ship');
        $chat->set('draft', '/preview')->call('send')->assertDispatched('workspace-tab', tab: 'preview');
        $chat->set('draft', '/review')->call('send')->assertDispatched('workspace-tab', tab: 'review');
        $chat->set('draft', '/gates')->call('send')->assertDispatched('workspace-run-gates');

        $this->assertCount(0, $this->userMessages());
    }

    public function test_claude_commands_pass_through(): void
    {
        $this->chat()->set('threadId', $this->thread->id)->set('draft', '/compact')->call('send');

        $this->assertSame('/compact', $this->userMessages()->sole()->payload['text']);
    }
}
