<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\PermissionMode;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

/**
 * "New task" from the composer: an automatic run (AutoTaskDriver) or a task
 * to chat in, with its options (base branch, ticket, follow-up, auto-merge),
 * a numbered default title, and the text to prefill — the ticket or the
 * follow-up brief, never sent by itself.
 */
class TaskComposer
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskTicketService $tickets,
        private readonly WorkspaceIssueService $issues,
    ) {}

    /**
     * @param  array{title?: string, prompt?: string, model?: string|null, mode?: string, base_branch?: string, issue?: array<string, mixed>|null, parent_id?: string|null, auto_merge?: bool, auto?: bool, plan?: string|null, ship?: string|null, max_fix_rounds?: int|null}  $form
     * @return array{task: Task, draft: string}
     */
    public function start(Workspace $workspace, User $by, AgentCredential $agent, array $form): array
    {
        $title = trim((string) ($form['title'] ?? ''));
        $options = array_filter([
            'base_branch' => trim((string) ($form['base_branch'] ?? '')) !== '' ? trim((string) $form['base_branch']) : null,
            'external_ref' => $form['issue'] ?? null,
            'parent_task_id' => $form['parent_id'] ?? null,
            'auto_merge' => ($form['auto_merge'] ?? false) ?: null,
        ], fn ($value): bool => $value !== null);

        if ($form['auto'] ?? false) {
            // Resolved lazily: the driver depends on TaskService.
            $task = app(AutoTaskDriver::class)->start(
                $workspace,
                $by,
                $agent,
                trim((string) ($form['prompt'] ?? '')),
                $title !== '' ? $title : null,
                $form['model'] ?? null,
                ['plan' => $form['plan'] ?? null, 'ship' => $form['ship'] ?? null, 'max_fix_rounds' => $form['max_fix_rounds'] ?? null],
                $options,
            );

            return ['task' => $task, 'draft' => ''];
        }

        $task = $this->tasks->create(
            $workspace,
            $by,
            $agent,
            $title !== '' ? $title : __('workspaces.task.title_default').' '.($workspace->tasks()->whereNull('archived_at')->count() + 1),
            $form['model'] ?? null,
            PermissionMode::from((string) ($form['mode'] ?? PermissionMode::Default->value)),
            options: $options,
        );

        return [
            'task' => $task,
            'draft' => $task->parent_task_id !== null ? $this->tickets->followUpBrief($task) : $this->issues->composerDraft($task),
        ];
    }
}
