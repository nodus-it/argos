<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\AuthMethod;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Services\OAuth\ConnectedAccountService;

/**
 * The credential pickers' value: `oauth:{account id}` (a connected account of
 * the person picking) or `pat:{credential id}` (a stored access token,
 * managed by administrators). Encoded, decoded and resolved only here — an
 * `oauth:` ref never resolves to somebody else's account.
 */
class CredentialRefResolver
{
    public function __construct(private readonly ConnectedAccountService $accounts) {}

    /**
     * Picker options, grouped.
     *
     * @param  list<string>  $accountProviders  connected-account providers (github, gitlab, …); [] = all
     * @param  list<string>|null  $credentialProviders  stored-token providers; null = all
     * @return array{oauth: array<string, string>, pat: array<string, string>}
     */
    public function options(User $user, array $accountProviders = [], ?array $credentialProviders = null, bool $withProvider = false): array
    {
        $oauth = [];
        foreach ($this->accounts->selectableFor($user, $accountProviders) as $account) {
            $oauth[self::forAccount($account)] = $account->displayLabel($withProvider);
        }

        $pat = [];
        $credentials = ProviderCredential::query()
            ->when($credentialProviders !== null, fn ($q) => $q->whereIn('provider', $credentialProviders))
            ->orderBy('label')
            ->get();
        foreach ($credentials as $credential) {
            $pat[self::forCredential($credential)] = $withProvider ? $credential->provider->label().' · '.$credential->label : $credential->label;
        }

        return ['oauth' => $oauth, 'pat' => $pat];
    }

    /** The person's own connected account behind an `oauth:` ref. */
    public function account(User $user, ?string $ref): ?ConnectedAccount
    {
        $id = self::accountId($ref);

        return $id !== null ? $user->connectedAccounts()->whereKey($id)->first() : null;
    }

    /** The stored access token behind a `pat:` ref. */
    public function credential(?string $ref): ?ProviderCredential
    {
        $id = self::credentialId($ref);

        return $id !== null ? ProviderCredential::query()->find($id) : null;
    }

    /**
     * The columns a binding or workspace stores for a ref.
     *
     * @return array{auth_method: string|null, connected_account_id: int|null, provider_credential_id: string|null}
     */
    public static function toColumns(?string $ref): array
    {
        return [
            'auth_method' => self::accountId($ref) !== null ? AuthMethod::OAuth->value : (self::credentialId($ref) !== null ? AuthMethod::Pat->value : null),
            'connected_account_id' => self::accountId($ref),
            'provider_credential_id' => self::credentialId($ref),
        ];
    }

    /** The ref for stored columns (edit forms pre-select it). */
    public static function fromColumns(?int $connectedAccountId, ?string $providerCredentialId): ?string
    {
        return match (true) {
            $providerCredentialId !== null && $providerCredentialId !== '' => 'pat:'.$providerCredentialId,
            $connectedAccountId !== null => 'oauth:'.$connectedAccountId,
            default => null,
        };
    }

    public static function forAccount(ConnectedAccount $account): string
    {
        return 'oauth:'.$account->id;
    }

    public static function forCredential(ProviderCredential $credential): string
    {
        return 'pat:'.$credential->id;
    }

    private static function accountId(?string $ref): ?int
    {
        return is_string($ref) && preg_match('/^oauth:(\d+)$/', $ref, $m) === 1 ? (int) $m[1] : null;
    }

    private static function credentialId(?string $ref): ?string
    {
        return is_string($ref) && preg_match('/^pat:([A-Za-z0-9]+)$/', $ref, $m) === 1 ? $m[1] : null;
    }
}
