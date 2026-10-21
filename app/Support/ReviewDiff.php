<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shapes the parsed workspace diff for the review panel: per file a status
 * letter, directory/name, a hash of its changes (for "seen") and hunks in
 * both layouts — unified rows and split rows that pair removed with added
 * lines — plus the unchanged gaps between hunks that can be expanded.
 */
final class ReviewDiff
{
    /** Lines an expanded gap shows at most. */
    public const MAX_GAP_LINES = 500;

    /**
     * @param  array<int, array<string, mixed>>  $files  DiffParser output
     * @return list<array{path: string, dir: string, name: string, status: string, additions: int, deletions: int, hash: string, deleted: bool, hunks: list<array<string, mixed>>}>
     */
    public static function files(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $path = (string) (($file['to_path'] ?? '') !== '' ? $file['to_path'] : ($file['from_path'] ?? ''));
            $deleted = (bool) ($file['is_deleted'] ?? false);
            $status = match (true) {
                (bool) ($file['is_new'] ?? false) => 'A',
                $deleted => 'D',
                ($file['from_path'] ?? $path) !== $path => 'R',
                default => 'M',
            };
            $dir = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/')) : '';

            $hunks = [];
            $previousEnd = 0;
            foreach ($file['hunks'] ?? [] as $i => $hunk) {
                [$oldStart, $newStart, $newCount] = self::range((string) $hunk['header']);
                $gapFrom = $previousEnd + 1;
                $gapTo = $newStart - 1;
                $hunks[] = [
                    'index' => $i,
                    'header' => (string) $hunk['header'],
                    'gap' => ! $deleted && $gapTo >= $gapFrom ? ['from' => $gapFrom, 'to' => $gapTo, 'offset' => $oldStart - $newStart] : null,
                    'lines' => $hunk['lines'],
                    'split' => self::split($hunk['lines']),
                ];
                $previousEnd = $newStart + max(0, $newCount) - 1;
            }

            $out[] = [
                'path' => $path,
                'dir' => $dir,
                'name' => basename($path),
                'status' => $status,
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                'hash' => sha1(serialize($file['hunks'] ?? [])),
                'deleted' => $deleted,
                'hunks' => $hunks,
            ];
        }

        return $out;
    }

    /**
     * Files grouped by directory, in diff order.
     *
     * @param  list<array{path: string, dir: string}>  $files
     * @return array<string, list<array<string, mixed>>>
     */
    public static function tree(array $files): array
    {
        $tree = [];
        foreach ($files as $file) {
            $tree[$file['dir']][] = $file;
        }

        return $tree;
    }

    /**
     * Pair runs of removed and added lines side by side; context lines sit on
     * both sides.
     *
     * @param  list<array{type: string, old_num: int|null, new_num: int|null, text: string}>  $lines
     * @return list<array{left: array<string, mixed>|null, right: array<string, mixed>|null}>
     */
    public static function split(array $lines): array
    {
        $rows = [];
        $dels = [];
        $adds = [];
        $flush = function () use (&$rows, &$dels, &$adds): void {
            $n = max(count($dels), count($adds));
            for ($i = 0; $i < $n; $i++) {
                $rows[] = ['left' => $dels[$i] ?? null, 'right' => $adds[$i] ?? null];
            }
            $dels = [];
            $adds = [];
        };

        foreach ($lines as $line) {
            if ($line['type'] === 'del') {
                if ($adds !== []) {
                    $flush();
                }
                $dels[] = $line;
            } elseif ($line['type'] === 'add') {
                $adds[] = $line;
            } else {
                $flush();
                $rows[] = ['left' => $line, 'right' => $line];
            }
        }
        $flush();

        return $rows;
    }

    /**
     * The unchanged lines of a gap, from the current file content.
     *
     * @param  array{from: int, to: int, offset: int}  $gap
     * @return list<array{type: string, old_num: int, new_num: int, text: string}>
     */
    public static function gapLines(string $content, array $gap): array
    {
        $all = explode("\n", $content);
        $lines = [];
        for ($n = $gap['from']; $n <= $gap['to'] && count($lines) < self::MAX_GAP_LINES; $n++) {
            if (! array_key_exists($n - 1, $all)) {
                break;
            }
            $lines[] = ['type' => 'context', 'old_num' => $n + $gap['offset'], 'new_num' => $n, 'text' => $all[$n - 1]];
        }

        return $lines;
    }

    /** @return array{0: int, 1: int, 2: int} old start, new start, new count */
    private static function range(string $header): array
    {
        if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $header, $m) !== 1) {
            return [1, 1, 0];
        }

        return [(int) $m[1], (int) $m[3], isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1];
    }
}
