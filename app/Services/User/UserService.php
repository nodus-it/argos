<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Services\EntityService;
use Filament\Facades\Filament;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
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
        // No password given: the person sets one through an access link.
        if (($data['password'] ?? '') === '') {
            $data['password'] = Str::password(40);
        }

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

    /**
     * A link that lets the person set a (new) password — handed over by an
     * administrator, so it works without mail. Valid for
     * ARGOS_ACCESS_LINK_HOURS; a newer link or a password change voids it.
     */
    public function accessLink(User $user): string
    {
        $broker = Password::broker('users');
        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('The users password broker cannot create tokens.');
        }
        $token = $broker->createToken($user);

        return Filament::getPanel('app')->getResetPasswordUrl($token, $user);
    }

    /** A ready-to-send welcome message with the access link and the first steps. */
    public function welcomeText(User $user, string $link): string
    {
        return __('users.access_link.welcome', [
            'name' => $user->name,
            'app' => (string) config('app.name', 'Argos'),
            'url' => rtrim((string) config('app.url'), '/'),
            'link' => $link,
            'email' => $user->email,
            'hours' => max(1, (int) config('auth.passwords.users.expire', 4320) / 60),
        ], $user->locale ?: null);
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

    /**
     * Set (or with null remove) a person's profile picture. A file path is
     * one already on User::AVATAR_DISK (e.g. from the profile's upload
     * field); an UploadedFile is stored first. The previous picture goes.
     */
    public function setAvatar(User $user, UploadedFile|string|null $avatar): void
    {
        $disk = Storage::disk(User::AVATAR_DISK);
        $path = $avatar instanceof UploadedFile ? $avatar->store('avatars', User::AVATAR_DISK) : $avatar;
        // A path from the form must be one of the uploaded pictures — never
        // another file on the disk (task attachments live there too).
        if (is_string($path) && preg_match('#^avatars/[A-Za-z0-9._-]+$#', $path) !== 1) {
            return;
        }
        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => $path === false ? null : $path])->save();
        if ($previous !== null && $previous !== $user->avatar_path) {
            $disk->delete($previous);
        }
    }

    /**
     * A person's own profile (My account → Profile): the picture (the
     * previous file goes) and the rest. Nobody makes themselves an
     * administrator here.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        if (array_key_exists('avatar_path', $data)) {
            $this->setAvatar($user, $data['avatar_path']);
            unset($data['avatar_path']);
        }
        unset($data['is_admin']);
        /** @var User $user */
        $user = $this->update($user, $data);

        return $user;
    }

    /** The language a person sees Argos in (`en` or `de`). */
    public function setLocale(User $user, string $locale): void
    {
        if (in_array($locale, ['en', 'de'], true)) {
            $user->forceFill(['locale' => $locale])->save();
        }
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
