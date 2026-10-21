<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoThreadDriver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ThreadPlanDecide extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'thread_plan_decide';

    protected string $description = 'Approves or rejects the plan an automatic run waits on (plan = approve). Rejecting with feedback makes Claude revise the plan.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'thread' => $schema->string()->description('Thread id (ULID)')->required(),
            'decision' => $schema->string()->description('approve or reject')->required(),
            'feedback' => $schema->string()->description('Why it was rejected / what to change')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['thread' => 'required|string', 'decision' => 'required|in:approve,reject', 'feedback' => 'nullable|string']);
        $thread = $this->findThread((string) $request->get('thread'));
        if ($thread === null) {
            return Response::error('Thread not found: '.$request->get('thread'));
        }

        return $this->attempt(function () use ($request, $thread): Response {
            app(AutoThreadDriver::class)->decidePlan($thread, $this->user(), $request->get('decision') === 'approve', $request->get('feedback'));

            return $this->threadJson($thread->fresh() ?? $thread);
        });
    }
}
