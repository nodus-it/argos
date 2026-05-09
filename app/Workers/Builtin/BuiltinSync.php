<?php

declare(strict_types=1);

namespace App\Workers\Builtin;

use App\Enums\WorkerImageEntityStatus;
use App\Models\WorkerStack;

/**
 * Mirrors the built-in stack manifest into worker_stacks.
 *
 * Sync is idempotent: rows whose `last_builtin_hash` matches the current
 * file hash are skipped. A change to the manifest entry or the referenced
 * dockerfile triggers an update of built-in fields only.
 *
 * Built-ins removed from the manifest flip to status=deprecated rather
 * than being deleted, since RepoProfiles may reference them as FK target.
 *
 * User-created rows (is_builtin=false) are never touched.
 *
 * Agents are not synced — they live entirely in code via AgentRegistry.
 */
class BuiltinSync
{
    public function __construct(private readonly BuiltinManifest $manifest) {}

    public static function default(): self
    {
        return new self(BuiltinManifest::default());
    }

    /**
     * Run the sync. Returns a summary keyed by counter name.
     *
     * @return array{created: int, updated: int, deprecated: int, unchanged: int}
     */
    public function sync(bool $dryRun = false): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deprecated' => 0, 'unchanged' => 0];
        $manifestNames = [];

        foreach ($this->manifest->stacks() as $entry) {
            $manifestNames[] = $entry['name'];
            $hash = $this->stackHash($entry);

            $existing = WorkerStack::query()
                ->where('name', $entry['name'])
                ->where('is_builtin', true)
                ->first();

            if ($existing === null) {
                if (! $dryRun) {
                    WorkerStack::query()->create([
                        ...$this->stackPayload($entry),
                        'is_builtin' => true,
                        'last_builtin_hash' => $hash,
                        'status' => WorkerImageEntityStatus::Active,
                    ]);
                }
                $stats['created']++;

                continue;
            }

            $hashUnchanged = $existing->last_builtin_hash === $hash;
            $alreadyActive = $existing->status === WorkerImageEntityStatus::Active;
            if ($hashUnchanged && $alreadyActive) {
                $stats['unchanged']++;

                continue;
            }

            if (! $dryRun) {
                $existing->forceFill([
                    ...$this->stackPayload($entry),
                    'last_builtin_hash' => $hash,
                    // re-activate if previously deprecated
                    'status' => WorkerImageEntityStatus::Active,
                ])->save();
            }
            $stats['updated']++;
        }

        // Mark missing built-ins as deprecated (NOT delete — FKs may reference them).
        $orphans = WorkerStack::query()
            ->where('is_builtin', true)
            ->whereNotIn('name', $manifestNames)
            ->where('status', '!=', WorkerImageEntityStatus::Deprecated)
            ->get();

        foreach ($orphans as $orphan) {
            if (! $dryRun) {
                $orphan->forceFill(['status' => WorkerImageEntityStatus::Deprecated])->save();
            }
            $stats['deprecated']++;
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function stackHash(array $entry): string
    {
        $body = $this->manifest->readFile($entry['dockerfile']);

        return hash('sha256', $this->canonical([
            'manifest' => $entry,
            'dockerfile_body' => $body,
        ]));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function stackPayload(array $entry): array
    {
        return [
            'name' => $entry['name'],
            'label' => $entry['label'],
            'base_image' => $entry['base_image'],
            'dockerfile_body' => $this->manifest->readFile($entry['dockerfile']),
            'common_tools' => $entry['common_tools'] ?? null,
            'capabilities' => $entry['capabilities'] ?? null,
        ];
    }

    /**
     * Canonical serialization for hashing — sorts keys recursively so the
     * hash doesn't depend on author-side key ordering in the manifest.
     */
    private function canonical(mixed $value): string
    {
        return json_encode($this->sortKeys($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->sortKeys($v), $value);
        }

        ksort($value);

        return array_map(fn ($v) => $this->sortKeys($v), $value);
    }
}
