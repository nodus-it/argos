<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Validates a Claude OAuth token against the Anthropic API.
 *
 * Returns true (valid), false (rejected by API), or null (unreachable).
 */
final class AnthropicTokenValidator
{
    public function validate(string $token): ?bool
    {
        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'anthropic-beta' => 'oauth-2025-04-20',
                    'User-Agent' => 'claude-code/2.0.31',
                ])
                ->timeout(5)
                ->get('https://api.anthropic.com/v1/models');

            if ($response->status() === 401 || $response->status() === 403) {
                return false;
            }

            return $response->successful();
        } catch (Throwable) {
            return null;
        }
    }
}
