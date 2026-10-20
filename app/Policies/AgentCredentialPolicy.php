<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentCredential;
use App\Models\User;

/**
 * Admins manage every agent. Other users manage their own agents (their own
 * Claude subscription) and may only use — never see or edit — shared ones.
 */
class AgentCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AgentCredential $agent): bool
    {
        return $user->isAdmin() || $agent->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, AgentCredential $agent): bool
    {
        return $this->view($user, $agent);
    }

    public function delete(User $user, AgentCredential $agent): bool
    {
        return $this->view($user, $agent);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function use(User $user, AgentCredential $agent): bool
    {
        return $agent->isShared() || $agent->user_id === $user->id;
    }
}
