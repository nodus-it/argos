<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

use App\Integrations\Anthropic\AnthropicConnector;
use App\Integrations\Anthropic\Requests\ValidateToken;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates a Claude OAuth token against the Anthropic API.
 *
 * Returns true (valid), false (rejected by API), or null (unreachable).
 *
 * Not final: the browser-E2E fake mode (E2eFakeServiceProvider) binds a
 * subclass that always returns valid, so no Anthropic call happens offline.
 */
class AnthropicTokenValidator
{
    public function validate(string $token): ?bool
    {
        try {
            $response = (new AnthropicConnector($token))->send(new ValidateToken);

            if ($response->status() === 401 || $response->status() === 403) {
                return false;
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Anthropic token validation unreachable', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return null;
        }
    }
}
