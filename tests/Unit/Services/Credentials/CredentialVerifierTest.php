<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Credentials;

use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\Credentials\CredentialVerifier;
use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use App\Services\IssueTracker\IssueTrackerRegistry;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CredentialVerifierTest extends TestCase
{
    private function fakeTracker(\Closure $listReferences): void
    {
        $tracker = Mockery::mock(IssueTrackerContract::class);
        $tracker->shouldReceive('listReferences')->andReturnUsing($listReferences);

        $registry = Mockery::mock(IssueTrackerRegistry::class);
        $registry->shouldReceive('makeRaw')->andReturn($tracker);
        $this->app->instance(IssueTrackerRegistry::class, $registry);
    }

    private function requestException(int $status): RequestException
    {
        return new RequestException(new Response(new GuzzleResponse($status, [], 'provider error body')));
    }

    public function test_provider_valid_when_listing_references_succeeds(): void
    {
        $this->fakeTracker(fn (): array => []);

        $this->assertTrue(app(CredentialVerifier::class)->verifyProvider('github', 'ghp_ok')->isValid());
    }

    public function test_provider_rejected_on_401(): void
    {
        $this->fakeTracker(fn () => throw $this->requestException(401));

        $result = app(CredentialVerifier::class)->verifyProvider('github', 'ghp_bad');

        $this->assertTrue($result->isRejected());
    }

    public function test_provider_rejected_on_400_like_linear_bearer(): void
    {
        $this->fakeTracker(fn () => throw $this->requestException(400));

        $this->assertTrue(app(CredentialVerifier::class)->verifyProvider('linear', 'lin_api_bad')->isRejected());
    }

    public function test_provider_unreachable_on_500(): void
    {
        $this->fakeTracker(fn () => throw $this->requestException(500));

        $result = app(CredentialVerifier::class)->verifyProvider('github', 'ghp_x');

        $this->assertFalse($result->isRejected());
        $this->assertFalse($result->isValid());
    }

    public function test_provider_unreachable_on_connection_error(): void
    {
        $this->fakeTracker(fn () => throw new RuntimeException('Could not resolve host'));

        $result = app(CredentialVerifier::class)->verifyProvider('github', 'ghp_x');

        $this->assertFalse($result->isRejected());
        $this->assertFalse($result->isValid());
    }

    public function test_claude_token_valid_rejected_unreachable(): void
    {
        $validator = Mockery::mock(AnthropicTokenValidator::class);
        $validator->shouldReceive('validate')->with('good')->andReturn(true);
        $validator->shouldReceive('validate')->with('bad')->andReturn(false);
        $validator->shouldReceive('validate')->with('offline')->andReturn(null);
        $this->app->instance(AnthropicTokenValidator::class, $validator);

        $verifier = app(CredentialVerifier::class);

        $this->assertTrue($verifier->verifyClaudeToken('good')->isValid());
        $this->assertTrue($verifier->verifyClaudeToken('bad')->isRejected());

        $offline = $verifier->verifyClaudeToken('offline');
        $this->assertFalse($offline->isValid());
        $this->assertFalse($offline->isRejected());
    }
}
