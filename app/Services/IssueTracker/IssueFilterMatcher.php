<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;
use Illuminate\Support\Arr;

/**
 * Decides whether a normalized issue passes a binding's ingest filters
 * (state and labels). Labels use OR semantics, per SETUP-TASK-PROVIDERS.md.
 */
class IssueFilterMatcher
{
    public function passes(ExternalIssue $issue, TaskProviderBinding $binding): bool
    {
        $filters = $binding->filters ?? [];

        $requiredState = Arr::get($filters, 'state');
        if ($requiredState !== null && $requiredState !== '') {
            if ($issue->state !== $requiredState) {
                return false;
            }
        }

        $requiredLabels = Arr::get($filters, 'labels');
        if (is_array($requiredLabels) && count($requiredLabels) > 0) {
            // OR semantics: the issue must carry at least one configured label.
            if (count(array_intersect($requiredLabels, $issue->labels)) === 0) {
                return false;
            }
        }

        return true;
    }
}
