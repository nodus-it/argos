<?php

declare(strict_types=1);

namespace App\Events\Credentials;

use App\Events\DomainEvent;
use App\Models\ProviderCredential;

/**
 * A stored provider credential's token was probed successfully and the
 * credential marked active.
 */
final class CredentialVerified extends DomainEvent
{
    public function __construct(public readonly ProviderCredential $credential)
    {
        parent::__construct();
    }
}
