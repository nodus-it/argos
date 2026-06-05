<?php

declare(strict_types=1);

namespace App\Testing;

use App\Services\Anthropic\AnthropicTokenValidator;

/**
 * Browser-E2E fake: treats every Claude token as valid so onboarding can
 * complete offline, without hitting the Anthropic API. Bound only by
 * E2eFakeServiceProvider (env-gated, never in production).
 */
class FakeAnthropicTokenValidator extends AnthropicTokenValidator
{
    public function validate(string $token): ?bool
    {
        return true;
    }
}
