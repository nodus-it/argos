<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The full set of providers Argos can hold credentials for. Spans both the
 * git providers (clone/push) and the task providers (issues). Deliberately
 * broader than GitProvider (no Linear) and TaskProviderKind (no Bitbucket) —
 * a single Personal Access Token or OAuth app may serve both roles.
 */
enum IntegrationProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Linear = 'linear';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Linear => 'Linear',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::GitHub => 'gray',
            self::GitLab => 'warning',
            self::Bitbucket => 'info',
            self::Linear => 'primary',
        };
    }
}
