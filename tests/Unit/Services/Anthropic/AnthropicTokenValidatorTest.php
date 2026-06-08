<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Anthropic;

use App\Services\Anthropic\AnthropicTokenValidator;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class AnthropicTokenValidatorTest extends TestCase
{
    public function test_returns_true_for_accepted_token(): void
    {
        Saloon::fake([
            'https://api.anthropic.com/v1/models' => MockResponse::make(['data' => []]),
        ]);

        $this->assertTrue((new AnthropicTokenValidator)->validate('valid-token'));

        Saloon::assertSent(function (Request $request, $response): bool {
            $pending = $response->getPendingRequest();

            return $pending->getUrl() === 'https://api.anthropic.com/v1/models'
                && $pending->headers()->get('Authorization') === 'Bearer valid-token'
                && $pending->headers()->get('anthropic-version') === '2023-06-01';
        });
    }

    public function test_returns_false_for_401(): void
    {
        Saloon::fake([
            'https://api.anthropic.com/v1/models' => MockResponse::make(['error' => 'unauthorized'], 401),
        ]);

        $this->assertFalse((new AnthropicTokenValidator)->validate('bad-token'));
    }

    public function test_returns_false_for_403(): void
    {
        Saloon::fake([
            'https://api.anthropic.com/v1/models' => MockResponse::make(['error' => 'forbidden'], 403),
        ]);

        $this->assertFalse((new AnthropicTokenValidator)->validate('bad-token'));
    }

    public function test_returns_false_for_server_error(): void
    {
        Saloon::fake([
            'https://api.anthropic.com/v1/models' => MockResponse::make(['error' => 'boom'], 500),
        ]);

        $this->assertFalse((new AnthropicTokenValidator)->validate('any-token'));
    }
}
