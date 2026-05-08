<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentCredentialStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('enums.agent_credential_status.active'),
            self::Expired => __('enums.agent_credential_status.expired'),
            self::Revoked => __('enums.agent_credential_status.revoked'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Expired => 'warning',
            self::Revoked => 'danger',
        };
    }
}
