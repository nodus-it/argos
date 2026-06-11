<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\ProviderOAuthConfig;
use App\Services\EntityService;

/**
 * Operations on a stored OAuth provider-app configuration. Currently plain
 * CRUD via the base; gains its own validation/connection-check methods as the
 * onboarding flow moves off the page.
 */
class ProviderOAuthConfigService extends EntityService
{
    protected function model(): string
    {
        return ProviderOAuthConfig::class;
    }
}
