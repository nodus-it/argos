<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoTaskDriver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskPlanDecide extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'task_plan_decide';

    protected string $description = 'Approves or rejects the plan an automatic run waits on (plan = approve). Rejecting with feedback makes Claude revise the plan.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID)')->required(),
            'decision' => $schema->string()->description('approve or reject')->required(),
            'feedback' => $schema->string()->description('Why it was rejected / what to change')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['task' => 'required|string', 'decision' => 'required|in:approve,reject', 'feedback' => 'nullable|string']);
        $task = $this->findTask((string) $request->get('task'));
        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        return $this->attempt(function () use ($request, $task): Response {
            app(AutoTaskDriver::class)->decidePlan($task, $this->user(), $request->get('decision') === 'approve', $request->get('feedback'));

            return $this->taskJson($task->fresh() ?? $task);
        });
    }
}
