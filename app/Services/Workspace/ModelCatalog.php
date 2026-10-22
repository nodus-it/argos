<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\AgentCredential;

/**
 * Which Claude models a task can pick — like Claude Code's `/model`. Claude
 * Code reports the models a subscription may use when a session starts:
 * aliases (`opus`, `sonnet[1m]`, …) that always resolve to the newest model,
 * each with the concrete version it resolves to today. Argos keeps that list
 * per agent and offers both: the alias ("always the newest") and the fixed
 * version (`claude-opus-5-5`), which stays put when a newer model ships. Any
 * other valid model id can be entered by hand. Before an agent's first
 * session only the aliases are offered — never fixed ids that age.
 */
class ModelCatalog
{
    /** Offered until a session reported the real list. */
    public const ALIASES = ['sonnet', 'opus', 'haiku'];

    /** Used when neither the caller nor the agent chose one. */
    public const DEFAULT = 'sonnet';

    /**
     * @param  array<int, mixed>  $models  `session.started.models` from the daemon
     */
    public function record(AgentCredential $agent, array $models): void
    {
        $clean = [];
        foreach ($models as $model) {
            if (! is_array($model) || ! is_string($model['value'] ?? null) || ! self::isValidId($model['value'])) {
                continue;
            }
            $resolved = $model['resolved_model'] ?? null;
            $clean[] = [
                'value' => $model['value'],
                'label' => mb_substr((string) ($model['display_name'] ?? $model['value']), 0, 80),
                'description' => mb_substr((string) ($model['description'] ?? ''), 0, 160),
                'resolved' => is_string($resolved) && self::isValidId($resolved) ? $resolved : null,
            ];
        }
        if ($clean !== [] && $clean !== $agent->models) {
            $agent->forceFill(['models' => $clean])->save();
        }
    }

    /**
     * Options for a select, grouped: `latest` (aliases, with today's
     * version), `pinned` (fixed versions) and `other` (the current value
     * when it is listed nowhere else, e.g. a hand-entered id).
     *
     * @return list<array{value: string, label: string, description: string, group: 'latest'|'pinned'|'other'}>
     */
    public function options(?AgentCredential $agent, ?string $current = null): array
    {
        $reported = $agent?->models ?? [];
        $options = [];
        if ($reported === []) {
            foreach (self::ALIASES as $alias) {
                $options[] = ['value' => $alias, 'label' => __('workspaces.models.alias', ['family' => ucfirst($alias)]), 'description' => '', 'group' => 'latest'];
            }
        }

        $pinned = [];
        foreach ($reported as $model) {
            $version = $model['resolved'] !== null ? self::versionLabel($model['resolved']) : null;
            $options[] = [
                'value' => $model['value'],
                'label' => $model['label'].($version !== null && ! str_contains($model['label'], $version) ? ' · '.$version : ''),
                'description' => $model['description'],
                'group' => 'latest',
            ];
            if ($model['resolved'] !== null && $model['resolved'] !== $model['value']) {
                $pinned[$model['resolved']] = [
                    'value' => $model['resolved'],
                    'label' => $version ?? $model['resolved'],
                    'description' => $model['resolved'],
                    'group' => 'pinned',
                ];
            }
        }
        // Not twice: an alias may itself be a fixed id.
        $options = [...$options, ...array_values(array_diff_key($pinned, array_flip(array_column($options, 'value'))))];

        if ($current !== null && $current !== '' && ! in_array($current, array_column($options, 'value'), true)) {
            $options[] = ['value' => $current, 'label' => self::versionLabel($current) ?? $current, 'description' => $current, 'group' => 'other'];
        }

        return $options;
    }

    /** How a model is shown: `claude-opus-5-5[1m]` → "Opus 5.5 (1M)"; null for aliases and unknown ids. */
    public static function versionLabel(string $model): ?string
    {
        if (preg_match('/^claude-([a-z]+)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?(\[1m\])?$/', $model, $m) !== 1) {
            return null;
        }

        return ucfirst($m[1]).' '.$m[2].(($m[3] ?? '') !== '' ? '.'.$m[3] : '').(($m[4] ?? '') !== '' ? ' (1M)' : '');
    }

    /** Model ids and aliases Claude Code accepts (e.g. `opus`, `claude-sonnet-5-5`, `sonnet[1m]`). */
    public static function isValidId(string $model): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.\-]{0,79}(\[[a-z0-9]{1,8}\])?$/', $model) === 1;
    }
}
