<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\GitProvider;
use App\Models\Workspace;
use App\Services\GitProvider\RepoTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepoCredentialEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function url(Workspace $workspace): string
    {
        return "/internal/workspaces/{$workspace->id}/repo-credential";
    }

    public function test_returns_the_git_credential_for_the_daemon_token(): void
    {
        $workspace = Workspace::factory()->create(['token' => 'ghp-secret', 'daemon_token' => 'tok-1']);

        $this->getJson($this->url($workspace), ['Authorization' => 'Bearer tok-1'])
            ->assertOk()
            ->assertExactJson(['username' => 'oauth2', 'password' => 'ghp-secret']);
    }

    public function test_rejects_missing_wrong_and_foreign_tokens(): void
    {
        $workspace = Workspace::factory()->create(['daemon_token' => 'tok-1']);
        $other = Workspace::factory()->create(['daemon_token' => 'tok-2']);

        $this->getJson($this->url($workspace))->assertUnauthorized();
        $this->getJson($this->url($workspace), ['Authorization' => 'Bearer nope'])->assertUnauthorized();
        $this->getJson($this->url($workspace), ['Authorization' => 'Bearer tok-2'])->assertUnauthorized();
        $this->getJson('/internal/workspaces/01JUNKNOWN0000000000000000/repo-credential', ['Authorization' => 'Bearer tok-1'])->assertUnauthorized();
        $this->getJson($this->url($other), ['Authorization' => 'Bearer tok-2'])->assertSuccessful();
    }

    public function test_requests_through_the_public_proxy_are_refused(): void
    {
        $workspace = Workspace::factory()->create(['daemon_token' => 'tok-1']);

        $this->getJson($this->url($workspace), ['Authorization' => 'Bearer tok-1', 'X-Forwarded-Host' => 'argos.example.com'])->assertNotFound();
    }

    public function test_reports_a_missing_repository_token(): void
    {
        $workspace = Workspace::factory()->create(['token' => null, 'daemon_token' => 'tok-1']);

        $this->getJson($this->url($workspace), ['Authorization' => 'Bearer tok-1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'credential_unavailable');
    }

    public function test_credential_usernames_follow_the_provider(): void
    {
        $resolver = app(RepoTokenResolver::class);

        $this->assertSame(['username' => 'x-token-auth', 'password' => 't'], $resolver->gitCredential(Workspace::factory()->make(['platform' => GitProvider::Bitbucket, 'token' => 't'])));
        $this->assertSame(['username' => 'oauth2', 'password' => 't'], $resolver->gitCredential(Workspace::factory()->make(['platform' => GitProvider::GitLab, 'token' => 't'])));
        $this->assertSame(['username' => 'me', 'password' => 'app:pw'], $resolver->gitCredential(Workspace::factory()->make(['platform' => GitProvider::Bitbucket, 'token' => 'me:app:pw'])));
    }
}
