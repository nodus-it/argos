<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Models\ExternalIssueLink;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

final class IssueCommentNotifier
{
    public function __construct(private readonly IssueTrackerRegistry $registry) {}

    /**
     * Post a phase-completion comment back to the external issue, if any.
     *
     * This is a No-Op when the task has no ExternalIssueLink. Errors are
     * caught and logged so the workflow never stalls because of a comment failure.
     */
    public function notifyPhaseCompletion(Task $task, string $phase, string $status): void
    {
        $link = ExternalIssueLink::query()
            ->where('task_id', $task->id)
            ->with('binding.connectedAccount')
            ->first();

        if (! $link instanceof ExternalIssueLink) {
            return;
        }

        $binding = $link->binding;

        if (! $this->registry->has($binding->kind)) {
            return;
        }

        try {
            $tracker = $this->registry->make($binding->kind, $binding);

            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');
            $issueNumber = $link->external_id;
            $body = $this->formatComment($task, $phase, $status);

            $tracker->createComment($owner, $project, $issueNumber, $body);
        } catch (\Throwable $e) {
            Log::channel('argos')->warning('IssueCommentNotifier: failed to post comment', [
                'task_id' => $task->id,
                'phase' => $phase,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatComment(Task $task, string $phase, string $status): string
    {
        $phaseLabel = ucfirst($phase);
        $statusLabel = ucfirst($status);

        // Build the link via the named route, NOT TaskResource::getUrl():
        // the notifier runs inside the queue worker, where no Filament panel is
        // current, and getUrl() throws "No default Filament panel is set" there.
        $taskUrl = route('filament.admin.resources.tasks.view', ['record' => $task->getKey()]);

        return "**Argos** — Phase **{$phaseLabel}** abgeschlossen mit Status: **{$statusLabel}**"
            ."\n\n[Task in Argos öffnen]({$taskUrl})";
    }

    /**
     * @return array{string, string}
     */
    private function parseRef(string $ref): array
    {
        $parts = explode('/', $ref, 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
