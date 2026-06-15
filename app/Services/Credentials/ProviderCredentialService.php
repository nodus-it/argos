<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\ProviderCredentialStatus;
use App\Events\Credentials\CredentialVerified;
use App\Models\ProviderCredential;
use App\Services\EntityService;

/**
 * Operations on a stored provider credential: plain CRUD via the base, plus the
 * domain-specific connection test that activates the credential.
 */
class ProviderCredentialService extends EntityService
{
    public function __construct(private readonly CredentialVerifier $verifier) {}

    protected function model(): string
    {
        return ProviderCredential::class;
    }

    /**
     * Probe the stored token with a cheap authenticated call. On success the
     * credential is marked active, timestamped, and a {@see CredentialVerified}
     * event is emitted; a rejection/unreachable leaves the status untouched.
     * Returns the verification so the caller can surface the provider's message.
     */
    public function testAndActivate(ProviderCredential $credential): CredentialVerification
    {
        $result = $this->verifier->verifyProvider(
            $credential->provider->value,
            $credential->token,
            $credential->getInstanceUrl(),
        );

        if ($result->isValid()) {
            $credential->forceFill([
                'status' => ProviderCredentialStatus::Active,
                'last_validated_at' => now(),
            ])->save();

            $this->emit(new CredentialVerified($credential));
        }

        return $result;
    }
}
