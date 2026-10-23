<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Broadcasting\TaskStream;
use App\Services\Workspace\TaskStreamPublisher;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskStreamPublisherTest extends TestCase
{
    public function test_truncates_large_tool_payloads_and_splits_batches(): void
    {
        Event::fake([TaskStream::class]);
        $events = [];
        for ($i = 0; $i < 6; $i++) {
            $events[] = ['type' => 'tool.result', 'seq' => $i + 1, 'data' => ['output' => str_repeat('x', 5000)]];
        }

        app(TaskStreamPublisher::class)->publish('ws1', 'th1', $events);

        $sent = [];
        Event::assertDispatched(TaskStream::class, function (TaskStream $stream) use (&$sent): bool {
            $sent[] = $stream;

            return true;
        });
        $this->assertGreaterThan(1, count($sent));
        foreach ($sent as $stream) {
            $this->assertLessThanOrEqual(TaskStreamPublisher::MESSAGE_LIMIT + 512, strlen((string) json_encode($stream->broadcastWith())));
            foreach ($stream->events as $event) {
                $this->assertTrue($event['data']['output_truncated']);
                $this->assertSame(TaskStreamPublisher::FIELD_LIMIT, strlen($event['data']['output']));
            }
        }
        $this->assertSame(6, array_sum(array_map(fn (TaskStream $s): int => count($s->events), $sent)));
    }

    public function test_broadcasts_on_the_workspace_presence_channel(): void
    {
        $stream = new TaskStream('ws1', 'th1', []);

        $this->assertSame('presence-workspace.ws1', $stream->broadcastOn()->name);
        $this->assertSame('task.stream', $stream->broadcastAs());
    }
}
