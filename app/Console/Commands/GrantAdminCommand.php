<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Recovery path for the admin role, e.g. when the only administrator lost
 * access: `php artisan argos:user:admin someone@example.com`.
 */
class GrantAdminCommand extends Command
{
    protected $signature = 'argos:user:admin {email : Email of the user} {--revoke : Remove the administrator role instead}';

    protected $description = 'Grant (or revoke) the Argos administrator role';

    public function handle(UserService $users): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error(__('users.command.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');
        try {
            $users->setAdmin($user, ! $revoke);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__($revoke ? 'users.command.revoked' : 'users.command.granted', ['email' => $email]));

        return self::SUCCESS;
    }
}
