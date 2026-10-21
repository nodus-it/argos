<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoThreadDriver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ThreadResume extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'thread_resume';

    protected string $description = 'Hands a paused, failed or budget-stopped thread back to automation: it checks the gates and ships.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread' => $schema->string()->description('Thread id (ULID)')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['thread' => 'required|string']);
        $thread = $this->findThread((string) $request->get('thread'));
        if ($thread === null) {
            return Response::error('Thread not found: '.$request->get('thread'));
        }

        return $this->attempt(function () use ($thread): Response {
            app(AutoThreadDriver::class)->resume($thread, $this->user());

            return $this->threadJson($thread->fresh() ?? $thread);
        });
    }
}
