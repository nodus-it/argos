<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiClient;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum tokens of API clients (administrators) and of a workspace (its
 * admins). The plaintext exists once, in the returned token; only the hash
 * is stored.
 */
class ApiTokenService
{
    /** The abilities a token may carry (mirrors the REST route gates). */
    public const ABILITIES = ['workspaces:read', 'workspaces:write', 'tasks:read', 'tasks:write'];

    /**
     * What a token of this owner may carry: a workspace's own token never
     * creates workspaces.
     *
     * @return list<string>
     */
    public function abilitiesFor(Model $owner): array
    {
        return $owner instanceof Workspace ? array_values(array_diff(self::ABILITIES, ['workspaces:write'])) : self::ABILITIES;
    }

    /** @param  list<string>  $abilities */
    public function mint(Model&HasApiTokens $owner, User $by, string $name, array $abilities): NewAccessToken
    {
        $this->authorize($owner, $by);
        $unknown = array_diff($abilities, $this->abilitiesFor($owner));
        if ($abilities === [] || $unknown !== []) {
            throw new InvalidArgumentException('Unknown token abilities: '.implode(', ', $unknown));
        }

        return $owner->createToken($name, array_values($abilities));
    }

    public function revoke(PersonalAccessToken $token, User $by): void
    {
        $owner = $token->tokenable;
        if (! $owner instanceof Model) {
            throw new AuthorizationException;
        }
        $this->authorize($owner, $by);
        $token->delete();
    }

    private function authorize(Model $owner, User $by): void
    {
        match (true) {
            $owner instanceof Workspace => Gate::forUser($by)->authorize('update', $owner),
            $owner instanceof ApiClient => Gate::forUser($by)->authorize('administer-argos'),
            default => throw new AuthorizationException,
        };
    }
}
