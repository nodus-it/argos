<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Models\TaskProviderBinding;
use Illuminate\Support\Arr;

/**
 * Decides whether a raw provider issue passes a binding's ingest filters
 * (state and labels). Labels use OR semantics, per SETUP-TASK-PROVIDERS.md.
 */
class IssueFilterMatcher
{
    /**
     * @param  array<string, mixed>  $issue
     */
    public function passes(array $issue, TaskProviderBinding $binding): bool
    {
        $filters = $binding->filters ?? [];

        $requiredState = Arr::get($filters, 'state');
        if ($requiredState !== null && $requiredState !== '') {
            $issueState = (string) ($issue['state'] ?? $issue['status'] ?? '');
            if ($issueState !== $requiredState) {
                return false;
            }
        }

        $requiredLabels = Arr::get($filters, 'labels');
        if (is_array($requiredLabels) && count($requiredLabels) > 0) {
            // OR semantics: the issue must carry at least one configured label.
            $issueLabels = $this->extractLabels($issue);
            if (count(array_intersect($requiredLabels, $issueLabels)) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return list<string>
     */
    private function extractLabels(array $issue): array
    {
        $raw = $issue['labels'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        return array_map(
            fn (mixed $l): string => is_array($l) ? (string) ($l['name'] ?? '') : (string) $l,
            $raw,
        );
    }
}
