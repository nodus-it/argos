<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;

/**
 * What a person still needs before their first task: a Claude subscription
 * to run it on and a workspace to run it in (only administrators add people
 * to workspaces).
 */
class FirstSteps
{
    public function __construct(private readonly AgentPool $agents) {}

    /**
     * @return array{agent: bool, workspace: bool, admins: list<string>}
     */
    public function for(User $user): array
    {
        return [
            'agent' => $this->agents->hasUsableAgent($user),
            'workspace' => $user->isAdmin() || Workspace::query()->whereHas('members', fn ($q) => $q->where('user_id', $user->id))->exists(),
            'admins' => User::query()->where('is_admin', true)->whereKeyNot($user->id)->orderBy('name')->limit(3)->pluck('name')->all(),
        ];
    }
}
