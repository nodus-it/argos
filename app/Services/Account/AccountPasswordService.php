<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;

/**
 * Persists a user's password. Keeps the write out of the presentation layer
 * (the onboarding wizard and profile screens ask this service instead of
 * mutating the model directly). The User model casts `password` as `hashed`,
 * so a plain value is hashed on assignment.
 */
class AccountPasswordService
{
    public function change(User $user, string $plainPassword): void
    {
        $user->forceFill(['password' => $plainPassword])->save();
    }
}
