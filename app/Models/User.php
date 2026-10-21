<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
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
 */
#[Fillable(['name', 'email', 'password', 'locale', 'notification_prefs'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, OAuthenticatable
{
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
    public const NOTIFICATION_KINDS = ['approval', 'turn', 'limit', 'error', 'ship', 'auto'];

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
}
