<?php

declare(strict_types=1);

namespace App\Services\Git;

class DiffParser
{
    /**
     * Parse a unified diff into a structured representation for GitHub-style rendering.
     *
     * @return array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<array{header: string, context_hint: string, lines: list<array{type: string, old_num: int|null, new_num: int|null, text: string}>}>}>
     */
    public function parse(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $files = [];
        $currentFile = null;
        $currentHunk = null;
        $oldLine = 0;
        $newLine = 0;

        foreach (explode("\n", $content) as $raw) {
            $line = (string) preg_replace('/\033\[[0-9;]*[mGKHFABCDJsu]/', '', $raw);

            if (str_starts_with($line, 'diff --git ')) {
                if ($currentFile !== null) {
                    if ($currentHunk !== null) {
                        $currentFile['hunks'][] = $currentHunk;
                        $currentHunk = null;
                    }
                    $files[] = $currentFile;
                }
                preg_match('/^diff --git a\/(.+) b\/(.+)$/', $line, $m);
                $currentFile = [
                    'from_path' => $m[1] ?? '',
                    'to_path' => $m[2] ?? '',
                    'is_new' => false,
                    'is_deleted' => false,
                    'additions' => 0,
                    'deletions' => 0,
                    'hunks' => [],
                ];

                continue;
            }

            if ($currentFile === null) {
                continue;
            }

            if (str_starts_with($line, 'new file')) {
                $currentFile['is_new'] = true;

                continue;
            }

            if (str_starts_with($line, 'deleted file')) {
                $currentFile['is_deleted'] = true;

                continue;
            }

            if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ') || str_starts_with($line, 'index ')) {
                continue;
            }

            if (str_starts_with($line, '@@')) {
                if ($currentHunk !== null) {
                    $currentFile['hunks'][] = $currentHunk;
                }
                preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m);
                $oldLine = isset($m[1]) ? (int) $m[1] : 1;
                $newLine = isset($m[2]) ? (int) $m[2] : 1;
                $currentHunk = [
                    'header' => $line,
                    'context_hint' => trim($m[3] ?? ''),
                    'lines' => [],
                ];

                continue;
            }

            if ($currentHunk === null) {
                continue;
            }

            $firstChar = $line !== '' ? $line[0] : ' ';

            if ($firstChar === '+') {
                $currentHunk['lines'][] = [
                    'type' => 'add',
                    'old_num' => null,
                    'new_num' => $newLine++,
                    'text' => substr($line, 1),
                ];
                $currentFile['additions']++;
            } elseif ($firstChar === '-') {
                $currentHunk['lines'][] = [
                    'type' => 'del',
                    'old_num' => $oldLine++,
                    'new_num' => null,
                    'text' => substr($line, 1),
                ];
                $currentFile['deletions']++;
            } else {
                $currentHunk['lines'][] = [
                    'type' => 'context',
                    'old_num' => $oldLine++,
                    'new_num' => $newLine++,
                    'text' => substr($line, 1),
                ];
            }
        }

        if ($currentHunk !== null && $currentFile !== null) {
            $currentFile['hunks'][] = $currentHunk;
        }
        if ($currentFile !== null) {
            $files[] = $currentFile;
        }

        return $files;
    }
}
