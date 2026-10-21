<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\AutoPlanMode;
use App\Enums\AutoShipMode;
use App\Models\Workspace;

/**
 * The effective settings of one auto-task run: config defaults, then the
 * project's `auto_settings`, then per-run overrides (UI/API/MCP). Unknown
 * keys and invalid values are dropped, so the stored config is always valid.
 */
final class AutoSettings
{
    public const KEYS = ['plan', 'ship', 'max_fix_rounds', 'max_turns', 'max_cost_usd', 'max_minutes'];

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{plan: string, ship: string, max_fix_rounds: int, max_turns: int, max_cost_usd: float, max_minutes: int}
     */
    public static function resolve(?Workspace $project, array $overrides = []): array
    {
        $settings = self::clean((array) config('argos.workspaces.auto', []));
        $settings = [...$settings, ...self::clean($project?->auto_settings ?? [])];
        $settings = [...$settings, ...self::clean($overrides)];

        return [
            'plan' => (string) ($settings['plan'] ?? AutoPlanMode::Auto->value),
            'ship' => (string) ($settings['ship'] ?? AutoShipMode::PullRequest->value),
            'max_fix_rounds' => (int) ($settings['max_fix_rounds'] ?? 3),
            'max_turns' => (int) ($settings['max_turns'] ?? 60),
            'max_cost_usd' => (float) ($settings['max_cost_usd'] ?? 0),
            'max_minutes' => (int) ($settings['max_minutes'] ?? 120),
        ];
    }

    /**
     * Keep known keys with valid values; empty values mean "inherit".
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function clean(array $values): array
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            $value = $values[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $valid = match ($key) {
                'plan' => AutoPlanMode::tryFrom((string) $value) !== null,
                'ship' => AutoShipMode::tryFrom((string) $value) !== null,
                'max_fix_rounds' => is_numeric($value) && (int) $value >= 0 && (int) $value <= 20,
                'max_turns' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 1000,
                'max_cost_usd' => is_numeric($value) && (float) $value >= 0,
                'max_minutes' => is_numeric($value) && (int) $value >= 1 && (int) $value <= 1440,
                default => false,
            };
            if ($valid) {
                $clean[$key] = in_array($key, ['plan', 'ship'], true) ? (string) $value : (is_numeric($value) && $key === 'max_cost_usd' ? (float) $value : (int) $value);
            }
        }

        return $clean;
    }
}
