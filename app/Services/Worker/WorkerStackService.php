<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Models\WorkerStack;
use App\Services\EntityService;
use Illuminate\Support\Facades\DB;

/**
 * Operations on a worker stack. Plain CRUD via the base, plus duplication of an
 * existing (built-in or user) stack into an editable user copy.
 */
class WorkerStackService extends EntityService
{
    protected function model(): string
    {
        return WorkerStack::class;
    }

    /**
     * Replicate a stack into a fresh, editable user copy: build-state columns
     * are dropped, is_builtin is forced off, and the name is made unique so
     * BuiltinSync can never overwrite it.
     */
    public function duplicate(WorkerStack $stack): WorkerStack
    {
        return DB::transaction(function () use ($stack): WorkerStack {
            $copy = $stack->replicate([
                'is_builtin',
                'last_builtin_hash',
                'last_built_at',
                'last_checked_at',
                'installed_version',
                'upstream_version',
                'has_update',
            ]);
            $copy->name = $this->uniqueName($stack->name.'-copy');
            $copy->label = $stack->label.' (Kopie)';
            $copy->is_builtin = false;
            $copy->save();

            return $copy;
        });
    }

    private function uniqueName(string $candidate): string
    {
        if (! WorkerStack::query()->where('name', $candidate)->exists()) {
            return $candidate;
        }

        for ($i = 2; $i <= 99; $i++) {
            $next = $candidate.'-'.$i;
            if (! WorkerStack::query()->where('name', $next)->exists()) {
                return $next;
            }
        }

        return $candidate.'-'.uniqid();
    }
}
