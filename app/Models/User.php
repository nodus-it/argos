<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * `is_admin` is deliberately not fillable: it is only set through
 * UserService, never from request data.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property array<string, bool>|null $notification_prefs
 * @property array<string, string>|null $ui_prefs
 * @property bool $auto_task_consent
 * @property string|null $avatar_path profile picture on User::AVATAR_DISK
 */
#[Fillable(['name', 'email', 'password', 'locale', 'avatar_path', 'notification_prefs', 'ui_prefs', 'auto_task_consent'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar, OAuthenticatable
{
    /** Where profile pictures live (private; served by UserAvatarController). */
    public const AVATAR_DISK = 'local';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'notification_prefs' => 'array',
            'ui_prefs' => 'array',
            'auto_task_consent' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ConnectedAccount, $this>
     */
    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    /** Workspace notification kinds a user can switch off. */
    public const NOTIFICATION_KINDS = ['approval', 'turn', 'limit', 'error', 'ship', 'auto', 'invite', 'ticket'];

    /** Whether the user wants workspace notifications of this kind (default: yes). */
    public function wantsNotification(string $kind): bool
    {
        return ($this->notification_prefs[$kind] ?? true) !== false;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Agents running on this user's own Claude subscription.
     *
     * @return HasMany<AgentCredential, $this>
     */
    public function agentCredentials(): HasMany
    {
        return $this->hasMany(AgentCredential::class);
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')->withPivot('role')->withTimestamps();
    }

    public function connectedAccount(string $provider): ?ConnectedAccount
    {
        /** @var ConnectedAccount|null */
        return $this->connectedAccounts()->where('provider', $provider)->first();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('access-argos-panel');
    }

    /** The profile picture's URL, or null without one (initials are shown then). */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : route('users.avatar', ['user' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 8)]);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatarUrl();
    }
}
