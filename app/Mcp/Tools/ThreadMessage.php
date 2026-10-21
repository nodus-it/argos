<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoThreadDriver;
use App\Services\Workspace\ThreadService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ThreadMessage extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'thread_message';

    protected string $description = 'Writes into a thread (e.g. review feedback). In a running automatic run this pauses automation unless `resume_auto` is true, which hands it back to automation after the message.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread' => $schema->string()->description('Thread id (ULID), from workspace_get')->required(),
            'text' => $schema->string()->description('The message')->required(),
            'resume_auto' => $schema->boolean()->description('Continue automatically after this message')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['thread' => 'required|string', 'text' => 'required|string', 'resume_auto' => 'nullable|boolean']);
        $thread = $this->findThread((string) $request->get('thread'));
        if ($thread === null) {
            return Response::error('Thread not found: '.$request->get('thread'));
        }

        return $this->attempt(function () use ($request, $thread): Response {
            app(ThreadService::class)->send($thread, $this->user(), (string) $request->get('text'));
            if ((bool) $request->get('resume_auto') && $thread->fresh()?->auto_state !== null) {
                app(AutoThreadDriver::class)->resume($thread->fresh(), $this->user());
            }

            return $this->threadJson($thread->fresh() ?? $thread);
        });
    }
}
