<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Broadcasting\ThreadStream;
use App\Services\Workspace\ThreadStreamPublisher;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ThreadStreamPublisherTest extends TestCase
{
    public function test_truncates_large_tool_payloads_and_splits_batches(): void
    {
        Event::fake([ThreadStream::class]);
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = ['type' => 'tool.result', 'seq' => $i + 1, 'data' => ['output' => str_repeat('x', 5000)]];
        }

        app(ThreadStreamPublisher::class)->publish('ws1', 'th1', $events);

        $sent = [];
        Event::assertDispatched(ThreadStream::class, function (ThreadStream $stream) use (&$sent): bool {
            $sent[] = $stream;

            return true;
        });
        $this->assertGreaterThan(1, count($sent));
        foreach ($sent as $stream) {
            $this->assertLessThanOrEqual(ThreadStreamPublisher::MESSAGE_LIMIT + 512, strlen((string) json_encode($stream->broadcastWith())));
            foreach ($stream->events as $event) {
                $this->assertTrue($event['data']['output_truncated']);
                $this->assertSame(ThreadStreamPublisher::FIELD_LIMIT, strlen($event['data']['output']));
            }
        }
        $this->assertSame(6, array_sum(array_map(fn (ThreadStream $s): int => count($s->events), $sent)));
    }

    public function test_broadcasts_on_the_workspace_presence_channel(): void
    {
        $stream = new ThreadStream('ws1', 'th1', []);

        $this->assertSame('presence-workspace.ws1', $stream->broadcastOn()->name);
        $this->assertSame('thread.stream', $stream->broadcastAs());
    }
}
