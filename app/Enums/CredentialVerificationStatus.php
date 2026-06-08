<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Credentials\CredentialVerifier;

/**
 * Outcome of a live credential check. Not persisted — purely the in-process
 * result of {@see CredentialVerifier}.
 *
 * - Valid:       the provider accepted the token.
 * - Rejected:    the provider gave a definitive "no" (auth/4xx) — block the save.
 * - Unreachable: no definitive answer (network / timeout / 5xx) — allow the save
 *                but do not mark the credential validated.
 */
enum CredentialVerificationStatus
{
    case Valid;
    case Rejected;
    case Unreachable;
}
