<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Models\Thread;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DaemonEventEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_a_batch_and_answers_with_acks(): void
    {
        Event::fake([ThreadStream::class]);
        $workspace = Workspace::factory()->create(['daemon_token' => 'tok']);
        $thread = Thread::factory()->create(['workspace_id' => $workspace->id]);

        $this->postJson("/internal/workspaces/{$workspace->id}/events", [
            'batch_id' => 'b1',
            'events' => [
                ['thread_id' => $thread->id, 'seq' => 1, 'type' => 'assistant.message', 'ts' => now()->toIso8601String(), 'data' => ['text' => 'hi']],
                ['thread_id' => null, 'seq' => 4, 'type' => 'gates.started', 'data' => []],
            ],
        ], ['Authorization' => 'Bearer tok'])
            ->assertOk()
            ->assertExactJson(['acks' => [$thread->id => 1, '_workspace' => 4]]);
    }

    public function test_empty_batches_answer_an_empty_ack_object(): void
    {
        $workspace = Workspace::factory()->create(['daemon_token' => 'tok']);

        $response = $this->postJson("/internal/workspaces/{$workspace->id}/events", ['batch_id' => 'b', 'events' => []], ['Authorization' => 'Bearer tok']);

        $response->assertOk();
        $this->assertSame('{"acks":{}}', $response->getContent());
    }

    public function test_rejects_bad_tokens_and_invalid_batches(): void
    {
        $workspace = Workspace::factory()->create(['daemon_token' => 'tok']);
        $url = "/internal/workspaces/{$workspace->id}/events";

        $this->postJson($url, ['batch_id' => 'b', 'events' => []], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
        $this->postJson($url, ['events' => [['seq' => 0, 'type' => '']]], ['Authorization' => 'Bearer tok'])->assertUnprocessable();
    }
}
