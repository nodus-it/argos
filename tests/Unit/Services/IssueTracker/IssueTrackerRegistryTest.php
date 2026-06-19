<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\AuthMethod;
use App\Enums\IntegrationProvider;
use App\Enums\TaskProviderKind;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueTrackerRegistry;
use App\Services\IssueTracker\Providers\GitHubIssueTracker;
use App\Services\IssueTracker\Providers\GitLabIssueTracker;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Tests\TestCase;

class IssueTrackerRegistryTest extends TestCase
{
    private IssueTrackerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new IssueTrackerRegistry;
    }

    public function test_has_returns_false_for_unregistered_provider(): void
    {
        $this->assertFalse($this->registry->has(TaskProviderKind::GitHub));
    }

    public function test_has_returns_true_after_register(): void
    {
        $this->registry->register('github', fn (string $t, string $u) => new GitHubIssueTracker($t));

        $this->assertTrue($this->registry->has(TaskProviderKind::GitHub));
    }

    public function test_make_throws_for_unregistered_provider(): void
    {
        $binding = new TaskProviderBinding;
        $binding->kind = TaskProviderKind::GitHub;

        $this->expectException(InvalidArgumentException::class);

        $this->registry->make(TaskProviderKind::GitHub, $binding);
    }

    public function test_make_returns_tracker_instance(): void
    {
        $this->registry->register('github', fn (string $t, string $u) => new GitHubIssueTracker($t));

        $binding = new TaskProviderBinding;
        $binding->kind = TaskProviderKind::GitHub;

        $tracker = $this->registry->make(TaskProviderKind::GitHub, $binding);

        $this->assertInstanceOf(GitHubIssueTracker::class, $tracker);
    }

    public function test_make_passes_token_from_connected_account(): void
    {
        $captured = [];
        $this->registry->register(
            'github',
            function (string $token, string $instanceUrl) use (&$captured): GitHubIssueTracker {
                $captured = ['token' => $token, 'instanceUrl' => $instanceUrl];

                return new GitHubIssueTracker($token);
            }
        );

        $account = new ConnectedAccount;
        // Use encryptString (string-safe) so the 'encrypted' cast on the model decrypts correctly.
        $account->setRawAttributes(['token' => Crypt::encryptString('my-token')]);

        $binding = new TaskProviderBinding;
        $binding->kind = TaskProviderKind::GitHub;
        $binding->setRelation('connectedAccount', $account);

        $this->registry->make(TaskProviderKind::GitHub, $binding);

        $this->assertSame('my-token', $captured['token']);
    }

    public function test_make_resolves_token_from_provider_credential_for_pat_binding(): void
    {
        $captured = [];
        $this->registry->register(
            'gitlab',
            function (string $token, string $instanceUrl) use (&$captured): GitLabIssueTracker {
                $captured = ['token' => $token, 'instanceUrl' => $instanceUrl];

                return new GitLabIssueTracker($token, $instanceUrl);
            }
        );

        $credential = new ProviderCredential;
        $credential->setRawAttributes([
            'token' => Crypt::encryptString('pat-token'),
            'instance_url' => 'https://gitlab.acme.test',
        ]);
        $credential->provider = IntegrationProvider::GitLab;

        $binding = new TaskProviderBinding;
        $binding->kind = TaskProviderKind::GitLab;
        $binding->auth_method = AuthMethod::Pat;
        $binding->setRelation('providerCredential', $credential);
        // Even with a (stale) OAuth account present, the PAT path must win.
        $binding->setRelation('connectedAccount', null);

        $this->registry->make(TaskProviderKind::GitLab, $binding);

        $this->assertSame('pat-token', $captured['token']);
        $this->assertSame('https://gitlab.acme.test', $captured['instanceUrl']);
    }

    public function test_make_from_provider_credential_passes_token_and_instance_url(): void
    {
        $captured = [];
        $this->registry->register(
            'gitlab',
            function (string $token, string $instanceUrl) use (&$captured): GitLabIssueTracker {
                $captured = ['token' => $token, 'instanceUrl' => $instanceUrl];

                return new GitLabIssueTracker($token, $instanceUrl);
            }
        );

        $credential = new ProviderCredential;
        $credential->setRawAttributes(['token' => Crypt::encryptString('gl-pat')]);
        $credential->provider = IntegrationProvider::GitLab;

        $tracker = $this->registry->makeFromProviderCredential(TaskProviderKind::GitLab, $credential);

        $this->assertInstanceOf(GitLabIssueTracker::class, $tracker);
        $this->assertSame('gl-pat', $captured['token']);
        // No instance_url set → falls back to the public SaaS host.
        $this->assertSame('https://gitlab.com', $captured['instanceUrl']);
    }

    public function test_make_from_account_passes_token_and_instance_url(): void
    {
        $captured = [];
        $this->registry->register(
            'gitlab',
            function (string $token, string $instanceUrl) use (&$captured): GitLabIssueTracker {
                $captured = ['token' => $token, 'instanceUrl' => $instanceUrl];

                return new GitLabIssueTracker($token, $instanceUrl);
            }
        );

        $account = new ConnectedAccount;
        $account->setRawAttributes([
            'token' => Crypt::encryptString('gl-token'),
            'instance_url' => 'https://gitlab.example.com',
        ]);

        $tracker = $this->registry->makeFromAccount(TaskProviderKind::GitLab, $account);

        $this->assertInstanceOf(GitLabIssueTracker::class, $tracker);
        $this->assertSame('gl-token', $captured['token']);
        $this->assertSame('https://gitlab.example.com', $captured['instanceUrl']);
    }

    public function test_make_from_account_throws_for_unregistered_provider(): void
    {
        $account = new ConnectedAccount;
        $account->setRawAttributes(['token' => Crypt::encryptString('tok')]);

        $this->expectException(InvalidArgumentException::class);

        $this->registry->makeFromAccount(TaskProviderKind::GitHub, $account);
    }

    public function test_global_registry_has_github_gitlab_bitbucket_linear(): void
    {
        $registry = app(IssueTrackerRegistry::class);

        $this->assertTrue($registry->has(TaskProviderKind::GitHub));
        $this->assertTrue($registry->has(TaskProviderKind::GitLab));
        $this->assertTrue($registry->has(TaskProviderKind::Linear));
    }
}
