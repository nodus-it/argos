<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Models\ConnectedAccount;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\GitHubIssueTracker;
use App\Services\IssueTracker\GitLabIssueTracker;
use App\Services\IssueTracker\IssueTrackerRegistry;
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
