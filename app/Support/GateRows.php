<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GateRunStatus;
use App\Models\WorkspaceGateRun;

/**
 * The quality gates as the page shows them: the four fixed rows (Pest and
 * PHPUnit share one) with their state, failed run keys and log, plus any
 * other gate that ran. Sidebar, action bar, review and gate detail all read
 * them from here.
 */
final class GateRows
{
    /** The four gates the sidebar always lists, with the run keys behind each row. */
    public const ROWS = [
        'pint' => ['label' => 'Pint', 'keys' => ['pint']],
        'pest' => ['label' => 'Pest / PHPUnit', 'keys' => ['pest', 'phpunit']],
        'phpstan' => ['label' => 'PHPStan', 'keys' => ['phpstan']],
        'artisan' => ['label' => 'Artisan', 'keys' => ['artisan']],
    ];

    /**
     * Gate rows with a state: open, running, pass, fail or skip. Gates outside
     * the four fixed rows appear once they ran.
     *
     * @return list<array{key: string, label: string, keys: list<string>, state: string, failed: list<string>, log: string}>
     */
    public static function of(?WorkspaceGateRun $run): array
    {
        $results = $run?->gates ?? [];
        $runningNow = $run?->status === GateRunStatus::Running;
        $rows = [];
        $covered = [];

        foreach (self::ROWS as $key => $row) {
            $found = array_values(array_filter(array_map(fn (string $k): ?string => $results[$k] ?? null, $row['keys'])));
            $covered = [...$covered, ...$row['keys']];
            $failed = array_values(array_filter($row['keys'], fn (string $k): bool => ($results[$k] ?? null) === 'fail'));
            $rows[] = ['key' => $key, 'label' => $row['label'], 'keys' => $row['keys'], 'state' => self::state($found, $runningNow), 'failed' => $failed, 'log' => self::logOf($run, $failed)];
        }
        foreach ($results as $key => $result) {
            if (! in_array($key, $covered, true) && $result !== 'skip') {
                $failed = $result === 'fail' ? [$key] : [];
                $rows[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'keys' => [$key], 'state' => $result, 'failed' => $failed, 'log' => self::logOf($run, $failed)];
            }
        }

        return $rows;
    }

    /** @param  list<string>  $keys */
    private static function logOf(?WorkspaceGateRun $run, array $keys): string
    {
        return implode("\n", array_filter(array_map(fn (string $k): string => (string) ($run?->logs[$k] ?? ''), $keys)));
    }

    /** @param  list<string>  $results */
    private static function state(array $results, bool $running): string
    {
        return match (true) {
            $running => 'running',
            in_array('fail', $results, true) => 'fail',
            in_array('pass', $results, true) => 'pass',
            $results !== [] => 'skip',
            default => 'open',
        };
    }

    /**
     * The rows that failed.
     *
     * @return list<array{key: string, label: string, keys: list<string>, state: string, failed: list<string>, log: string}>
     */
    public static function failed(?WorkspaceGateRun $run): array
    {
        return array_values(array_filter(self::of($run), fn (array $row): bool => $row['state'] === 'fail'));
    }
}
