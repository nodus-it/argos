<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Broadcasting\TaskStream;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts task events to the workspace presence channel within the
 * Pusher protocol's message size: large tool payloads are truncated (the
 * full content stays in task_events and is loaded on demand) and batches
 * are split into several messages.
 */
class TaskStreamPublisher
{
    /** Per-field cap for tool input/output in a broadcast. */
    public const FIELD_LIMIT = 2048;

    /** Target size of one broadcast message (JSON bytes). */
    public const MESSAGE_LIMIT = 8000;

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function publish(string $workspaceId, string $taskId, array $events): void
    {
        $batch = [];
        $size = 0;
        foreach ($events as $event) {
            $event = $this->shrink($event);
            $bytes = strlen((string) json_encode($event));
            if ($batch !== [] && $size + $bytes > self::MESSAGE_LIMIT) {
                $this->send($workspaceId, $taskId, $batch);
                $batch = [];
                $size = 0;
            }
            $batch[] = $event;
            $size += $bytes;
        }
        if ($batch !== []) {
            $this->send($workspaceId, $taskId, $batch);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function shrink(array $event): array
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        foreach (['input', 'output', 'request', 'text'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = is_string($data[$field]) ? $data[$field] : (string) json_encode($data[$field]);
            if (strlen($value) > self::FIELD_LIMIT && ($event['type'] ?? '') !== 'assistant.delta') {
                $data[$field] = mb_strcut($value, 0, self::FIELD_LIMIT);
                $data[$field.'_truncated'] = true;
            }
        }
        $event['data'] = $data;

        // A single delta can still exceed the limit; cut it into pieces.
        if (strlen((string) json_encode($event)) > self::MESSAGE_LIMIT && is_string($data['text'] ?? null)) {
            $data['text'] = mb_strcut($data['text'], 0, self::MESSAGE_LIMIT - 1024);
            $data['text_truncated'] = true;
            $event['data'] = $data;
        }

        return $event;
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function send(string $workspaceId, string $taskId, array $batch): void
    {
        try {
            broadcast(new TaskStream($workspaceId, $taskId, $batch));
        } catch (Throwable $e) {
            // Realtime is best effort: history is persisted and browsers
            // catch up on reconnect.
            Log::channel('argos')->warning('Task broadcast failed', ['task' => $taskId, 'error' => $e->getMessage()]);
        }
    }
}
