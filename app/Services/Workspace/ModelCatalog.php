<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\AgentCredential;
use Illuminate\Support\Facades\Cache;

/**
 * Which Claude models a thread can pick. Claude Code reports the models a
 * subscription may use when a session starts (aliases like `sonnet` always
 * resolve to the newest model); Argos remembers that list per agent. Before
 * an agent's first session the aliases are offered — never fixed model ids
 * that age.
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
            $clean[] = [
                'value' => $model['value'],
                'label' => mb_substr((string) ($model['display_name'] ?? $model['value']), 0, 80),
                'description' => mb_substr((string) ($model['description'] ?? ''), 0, 160),
            ];
        }
        if ($clean !== []) {
            Cache::forever($this->key($agent), $clean);
        }
    }

    /**
     * Options for a select; the current value is kept even if no longer listed.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public function options(?AgentCredential $agent, ?string $current = null): array
    {
        $options = $agent !== null ? Cache::get($this->key($agent), []) : [];
        if (! is_array($options) || $options === []) {
            $options = array_map(fn (string $alias): array => [
                'value' => $alias,
                'label' => __('workspaces.models.alias', ['family' => ucfirst($alias)]),
                'description' => '',
            ], self::ALIASES);
        }

        if ($current !== null && $current !== '' && ! in_array($current, array_column($options, 'value'), true)) {
            $options[] = ['value' => $current, 'label' => $current, 'description' => ''];
        }

        return array_values($options);
    }

    /** Model ids and aliases Claude Code accepts (e.g. `opus`, `claude-sonnet-5-5`, `sonnet[1m]`). */
    public static function isValidId(string $model): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.\-]{0,79}(\[[a-z0-9]{1,8}\])?$/', $model) === 1;
    }

    private function key(AgentCredential $agent): string
    {
        return 'workspaces:models:'.$agent->id;
    }
}
