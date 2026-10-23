<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkspaceGateRun;

/**
 * Findings of the last gate run that point at code, for the review diff:
 * PHPStan's new errors (`path:line: message` in its log) and the files Pint
 * flagged. Other gates have no file/line and stay chips with a log.
 */
final class GateAnnotations
{
    /**
     * @return array{phpstan: array<string, list<array{line: int, message: string}>>, pint: list<string>}
     */
    public static function fromRun(?WorkspaceGateRun $run): array
    {
        $out = ['phpstan' => [], 'pint' => []];
        if ($run === null) {
            return $out;
        }

        if (($run->gates['phpstan'] ?? null) === 'fail') {
            $out['phpstan'] = self::phpstanFindings((string) ($run->logs['phpstan'] ?? ''));
        }

        if (($run->gates['pint'] ?? null) === 'fail') {
            $out['pint'] = self::pintFiles((string) ($run->logs['pint'] ?? ''));
        }

        return $out;
    }

    /**
     * PHPStan errors per file from its log (`path:line: message` lines).
     *
     * @return array<string, list<array{line: int, message: string}>>
     */
    public static function phpstanFindings(string $log): array
    {
        $findings = [];
        foreach (explode("\n", $log) as $line) {
            if (preg_match('/^([^\s:][^:]*):(\d+): (.+)$/', trim($line), $m) === 1) {
                $findings[$m[1]][] = ['line' => (int) $m[2], 'message' => trim($m[3])];
            }
        }

        return $findings;
    }

    /**
     * Files Pint flagged in its log, relative to the checkout.
     *
     * @return list<string>
     */
    public static function pintFiles(string $log): array
    {
        preg_match_all('/([\w.\-\/]+\.php)\b/', $log, $matches);

        return array_values(array_unique(array_map(fn (string $p): string => (string) preg_replace('#^/?workspace/#', '', $p), $matches[1])));
    }

    /**
     * Findings per file (PHPStan errors + one for a Pint-flagged file).
     *
     * @param  array{phpstan: array<string, list<array{line: int, message: string}>>, pint: list<string>}  $annotations
     * @return array<string, int>
     */
    public static function counts(array $annotations): array
    {
        $counts = array_map('count', $annotations['phpstan']);
        foreach ($annotations['pint'] as $path) {
            $counts[$path] = ($counts[$path] ?? 0) + 1;
        }

        return $counts;
    }
}
