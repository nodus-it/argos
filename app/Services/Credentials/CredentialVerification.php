<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Enums\CredentialVerificationStatus;

/**
 * Immutable result of a credential verification: a status plus an optional
 * human-readable message (the provider's error) for the Rejected/Unreachable
 * cases. Carries no secrets.
 */
final readonly class CredentialVerification
{
    public function __construct(
        public CredentialVerificationStatus $status,
        public ?string $message = null,
    ) {}

    public static function valid(): self
    {
        return new self(CredentialVerificationStatus::Valid);
    }

    public static function rejected(?string $message = null): self
    {
        return new self(CredentialVerificationStatus::Rejected, $message);
    }

    public static function unreachable(?string $message = null): self
    {
        return new self(CredentialVerificationStatus::Unreachable, $message);
    }

    public function isValid(): bool
    {
        return $this->status === CredentialVerificationStatus::Valid;
    }

    public function isRejected(): bool
    {
        return $this->status === CredentialVerificationStatus::Rejected;
    }
}
