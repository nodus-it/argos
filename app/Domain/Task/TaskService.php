<?php

declare(strict_types=1);

namespace App\Domain\Task;

use App\Models\Task;
use Illuminate\Support\Collection;

class TaskService
{
    public function create(array $data): Task
    {
        return Task::create([
            'name' => $data['name'],
            'repo_profile_id' => $data['repo_profile_id'] ?? null,
            'description' => $data['description'],
        ]);
    }

    public function list(): Collection
    {
        return Task::with('repoProfile')->get();
    }

    public function find(string $nameOrId): ?Task
    {
        return Task::where('name', $nameOrId)->orWhere('id', $nameOrId)->first();
    }

    public function delete(Task $task): void
    {
        $task->delete();
    }
}
