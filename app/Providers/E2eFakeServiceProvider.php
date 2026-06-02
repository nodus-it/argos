<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Anthropic\AnthropicTokenValidator;
use App\Testing\FakeAnthropicTokenValidator;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Registers deterministic, offline replacements for the worker run and the
 * external validations the UI performs, so the browser-E2E suite can drive the
 * full flow without real tokens, API calls, or `docker run`.
 *
 * Only registered by AppServiceProvider when ARGOS_E2E_FAKE is set and the app
 * is not in production. The production guard below is defence-in-depth: even if
 * this provider were registered directly, it refuses to boot in production.
 *
 * Bindings are added incrementally per build phase:
 *   - AnthropicTokenValidator (this slice)
 *   - Git provider services + branch validator (next)
 *   - PhaseRunner (next)
 */
class E2eFakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->isProduction()) {
            throw new RuntimeException('E2eFakeServiceProvider must never boot in production.');
        }

        $this->app->bind(AnthropicTokenValidator::class, fn (): FakeAnthropicTokenValidator => new FakeAnthropicTokenValidator);
    }
}
