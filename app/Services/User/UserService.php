<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\EntityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * User administration. `is_admin` is not mass-assignable on the model, so
 * this service is the only place that grants or revokes it — and it keeps at
 * least one administrator around.
 */
class UserService extends EntityService
{
    protected function model(): string
    {
        return User::class;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model
    {
        $isAdmin = (bool) ($data['is_admin'] ?? false);
        unset($data['is_admin']);

        return DB::transaction(function () use ($data, $isAdmin): User {
            $user = new User;
            $user->fill($data);
            $user->forceFill(['is_admin' => $isAdmin]);
            $user->save();

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $record, array $data): Model
    {
        /** @var User $record */
        $wantsAdmin = array_key_exists('is_admin', $data) ? (bool) $data['is_admin'] : null;
        unset($data['is_admin']);
        if (($data['password'] ?? '') === '') {
            unset($data['password']);
        }

        return DB::transaction(function () use ($record, $data, $wantsAdmin): User {
            $record->fill($data);
            if ($wantsAdmin !== null) {
                $this->assertCanChangeAdmin($record, $wantsAdmin);
                $record->forceFill(['is_admin' => $wantsAdmin]);
            }
            $record->save();

            return $record;
        });
    }

    public function delete(Model $record): void
    {
        /** @var User $record */
        DB::transaction(function () use ($record): void {
            if ($record->isAdmin()) {
                $this->assertOtherAdminExists($record);
            }
            $record->delete();
        });
    }

    /** Delete a user (keeps the last administrator). */
    public function remove(User $user): void
    {
        $this->delete($user);
    }

    public function setAdmin(User $user, bool $isAdmin): void
    {
        DB::transaction(function () use ($user, $isAdmin): void {
            $this->assertCanChangeAdmin($user, $isAdmin);
            $user->forceFill(['is_admin' => $isAdmin])->save();
        });
    }

    private function assertCanChangeAdmin(User $user, bool $isAdmin): void
    {
        if ($isAdmin || ! $user->isAdmin()) {
            return;
        }
        if (auth()->id() === $user->id) {
            throw new RuntimeException(__('users.errors.cannot_demote_self'));
        }
        $this->assertOtherAdminExists($user);
    }

    private function assertOtherAdminExists(User $user): void
    {
        $others = User::query()->where('is_admin', true)->whereKeyNot($user->getKey())->lockForUpdate()->count();
        if ($others === 0) {
            throw new RuntimeException(__('users.errors.last_admin'));
        }
    }
}
