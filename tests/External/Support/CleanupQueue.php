<?php

declare(strict_types=1);

namespace Tests\External\Support;

use Throwable;

/**
 * Collects cleanup callbacks during a test and runs them in LIFO order in
 * tearDown. Errors in one callback do not block the others — every cleanup
 * step gets a chance to run, since each one corresponds to a different
 * remote artefact (PR, branch, issue) that needs to come down.
 */
final class CleanupQueue
{
    /** @var list<array{0: string, 1: callable}> */
    private array $items = [];

    /** @var list<string> */
    private array $errors = [];

    public function push(string $label, callable $fn): void
    {
        $this->items[] = [$label, $fn];
    }

    public function run(): void
    {
        // LIFO so we close the PR before deleting the branch it referenced.
        while ($item = array_pop($this->items)) {
            [$label, $fn] = $item;
            try {
                $fn();
            } catch (Throwable $e) {
                $this->errors[] = "[{$label}] {$e->getMessage()}";
            }
        }
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
