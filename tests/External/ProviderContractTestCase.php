<?php

declare(strict_types=1);

namespace Tests\External;

use App\Services\GitProvider\Contracts\GitProviderContract;
use App\Services\GitProvider\RemoteBranchValidator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\External\Support\AuthenticatedCloneUrl;
use Tests\External\Support\CleanupQueue;
use Tests\External\Support\DestructiveOperationGuard;
use Tests\External\Support\ProviderTestConfig;
use Tests\External\Support\RandomizedRefName;
use Tests\TestCase;

/**
 * Shared test body for every Git provider's external contract suite.
 *
 * Subclasses only deliver provider-specific glue: how to instantiate the
 * service, how to extract the PR identifier, and how to close the PR for
 * cleanup. Every test method below runs unchanged across all providers.
 *
 * The suite tests the PAT path only. OAuth round-trips share the same
 * Bearer-auth code path on GitHub and GitLab and would not exercise new
 * code; on Bitbucket the OAuth path is exercised manually via the
 * `test:providers` artisan helper, which feeds DB-resident OAuth tokens
 * into the same suite.
 */
abstract class ProviderContractTestCase extends TestCase
{
    protected ProviderTestConfig $config;

    protected CleanupQueue $cleanup;

    abstract protected function makeConfig(): ProviderTestConfig;

    abstract protected function makeService(string $token): GitProviderContract;

    /**
     * Provider-specific PR identifier extraction. GitHub uses 'number',
     * GitLab uses 'iid', Bitbucket uses 'id'.
     *
     * @param  array<string, mixed>  $createResponse
     */
    abstract protected function pullRequestId(array $createResponse): int|string;

    /**
     * Closes the given pull request via direct HTTP. Used in cleanup.
     * Lives in the subclass because closePullRequest is not part of the
     * contract (yet) and the endpoint differs per provider.
     */
    abstract protected function closePullRequestViaApi(int|string $id, string $token): void;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup = new CleanupQueue;
        $this->config = $this->makeConfig();

        if (! $this->config->isFullyConfigured()) {
            $this->markTestSkipped(
                "External-Suite für {$this->config->providerKey}: PAT nicht gesetzt (siehe .env.testing.external)."
            );
        }

        DestructiveOperationGuard::assertScopedTo($this->config);
    }

    protected function tearDown(): void
    {
        if (isset($this->cleanup)) {
            $this->cleanup->run();

            $errors = $this->cleanup->errors();
            if ($errors !== [] && isset($this->config)) {
                fwrite(
                    STDERR,
                    "\n[cleanup warnings for {$this->config->providerKey}]\n"
                    .implode("\n", array_map(static fn (string $e) => "  - {$e}", $errors))
                    ."\n"
                );
            }
        }

        parent::tearDown();
    }

    protected function token(): string
    {
        return $this->config->patToken ?? '';
    }

    public function test_list_repositories_includes_test_repo(): void
    {
        $service = $this->makeService($this->token());

        $repos = $service->listRepositories();

        $this->assertNotEmpty($repos, 'listRepositories() lieferte ein leeres Ergebnis.');

        $expectedFullName = "{$this->config->testRepoOwner}/{$this->config->testRepo}";
        $found = false;
        foreach ($repos as $repo) {
            $name = $repo['full_name'] ?? ($repo['path_with_namespace'] ?? null);
            if (is_string($name) && strcasecmp($name, $expectedFullName) === 0) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            "Test-Repo {$expectedFullName} nicht in listRepositories() gefunden — Token-Scope korrekt? Repo wirklich auf den Token-Account?"
        );
    }

    public function test_list_branches_includes_default_branch(): void
    {
        $service = $this->makeService($this->token());

        $branches = $service->listBranches($this->config->testRepoOwner, $this->config->testRepo);

        $this->assertNotEmpty($branches, 'listBranches() lieferte ein leeres Ergebnis.');

        $names = array_filter(array_map(
            static fn (array $b): ?string => is_string($b['name'] ?? null) ? $b['name'] : null,
            $branches,
        ));

        $this->assertContains(
            $this->config->defaultBranch,
            $names,
            "Default-Branch '{$this->config->defaultBranch}' nicht in listBranches gefunden."
        );
    }

    public function test_get_default_branch_returns_configured_default(): void
    {
        $service = $this->makeService($this->token());

        $branch = $service->getDefaultBranch(
            "{$this->config->testRepoOwner}/{$this->config->testRepo}"
        );

        $this->assertSame(
            $this->config->defaultBranch,
            $branch,
            "getDefaultBranch lieferte '{$branch}', erwartet war '{$this->config->defaultBranch}'."
        );
    }

    public function test_remote_branch_validator_finds_default_branch(): void
    {
        $validator = new RemoteBranchValidator;

        $result = $validator->validate(
            $this->config->repoCloneUrl,
            $this->config->defaultBranch,
            $this->token(),
        );

        $this->assertTrue(
            $result['ok'],
            'RemoteBranchValidator::validate() schlug fehl: '.($result['error'] ?? '(keine Fehlermeldung)')
        );
    }

    public function test_git_clone_succeeds(): void
    {
        $authUrl = AuthenticatedCloneUrl::build(
            $this->config->providerKey,
            $this->config->repoCloneUrl,
            $this->token(),
        );

        $tmp = $this->makeTempDir('clone');

        $process = new Process(['git', 'clone', '--depth=1', $authUrl, $tmp]);
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "git clone fehlgeschlagen.\nstderr:\n".AuthenticatedCloneUrl::scrub($process->getErrorOutput())
        );
        $this->assertFileExists("{$tmp}/.git/HEAD");
    }

    public function test_pull_request_round_trip(): void
    {
        $token = $this->token();
        $service = $this->makeService($token);

        $branchName = RandomizedRefName::branch('pr-roundtrip');
        $title = RandomizedRefName::pullRequestTitle('round-trip');

        $authUrl = AuthenticatedCloneUrl::build(
            $this->config->providerKey,
            $this->config->repoCloneUrl,
            $token,
        );

        $workdir = $this->makeTempDir('pr');
        $this->cleanup->push(
            "delete remote branch {$branchName}",
            fn () => $this->runGit($workdir, ['git', 'push', '--quiet', 'origin', '--delete', $branchName]),
        );

        $this->runGit($workdir, ['git', 'clone', '--depth=1', $authUrl, $workdir], expectExit: 0);
        $this->runGit($workdir, ['git', '-C', $workdir, 'config', 'user.email', 'argos-test@example.invalid']);
        $this->runGit($workdir, ['git', '-C', $workdir, 'config', 'user.name', 'Argos Contract Test']);
        $this->runGit($workdir, ['git', '-C', $workdir, 'checkout', '-b', $branchName]);

        file_put_contents("{$workdir}/argos-test.txt", 'round-trip '.date('c')."\n");
        $this->runGit($workdir, ['git', '-C', $workdir, 'add', 'argos-test.txt']);
        $this->runGit($workdir, ['git', '-C', $workdir, 'commit', '-m', 'argos contract test commit']);
        $this->runGit($workdir, ['git', '-C', $workdir, 'push', '--quiet', 'origin', $branchName]);

        DestructiveOperationGuard::assertOperatesOn(
            $this->config,
            $this->config->testRepoOwner,
            $this->config->testRepo,
        );

        $pr = $service->createPullRequest(
            $this->config->testRepoOwner,
            $this->config->testRepo,
            $title,
            'Created by argos provider contract suite. Safe to close.',
            $branchName,
            $this->config->defaultBranch,
        );

        $this->assertNotEmpty($pr, 'createPullRequest() lieferte leere Antwort.');

        $prId = $this->pullRequestId($pr);
        $this->cleanup->push(
            "close PR {$prId}",
            fn () => $this->closePullRequestViaApi($prId, $token),
        );

        $this->assertNotSame('', (string) $prId, 'Konnte PR-Id nicht aus Response extrahieren.');

        // Same flow the worker's push phase exercises: post an iteration
        // comment on the freshly created PR. No explicit cleanup — comments
        // are tied to the PR and disappear when the PR gets closed in tearDown.
        $comment = $service->commentOnPullRequest(
            $this->config->testRepoOwner,
            $this->config->testRepo,
            $prId,
            'argos contract test comment',
        );

        $this->assertNotEmpty($comment, 'commentOnPullRequest lieferte leere Antwort.');

        // Refresh title and description on the same PR. Worker calls this on
        // re-iteration to update the PR body with the latest implementation
        // summary. No cleanup either — closing the PR discards the metadata.
        $updated = $service->updatePullRequest(
            $this->config->testRepoOwner,
            $this->config->testRepo,
            $prId,
            'argos contract test [updated]',
            'Updated by argos contract suite.',
        );

        $this->assertNotEmpty($updated, 'updatePullRequest lieferte leere Antwort.');
    }

    /**
     * Helper for direct HTTP calls in cleanup hooks (e.g. closing a PR). Uses
     * Laravel's HTTP client with the same scrubbing/throw-on-4xx semantics
     * as the production provider services.
     *
     * @param  array<string, string>  $extraHeaders
     */
    protected function http(string $token, array $extraHeaders = []): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            ...$extraHeaders,
        ]);
    }

    /** @param  array<int, string>  $cmd */
    private function runGit(string $cwd, array $cmd, ?int $expectExit = null): void
    {
        $process = new Process($cmd, is_dir($cwd) ? $cwd : null);
        $process->setTimeout(60);
        $process->run();

        if ($expectExit !== null && $process->getExitCode() !== $expectExit) {
            $this->fail(
                "Erwarteter exit {$expectExit}, war ".$process->getExitCode().".\nstderr:\n"
                .AuthenticatedCloneUrl::scrub($process->getErrorOutput())
            );
        }
    }

    private function makeTempDir(string $purpose): string
    {
        $base = sys_get_temp_dir().'/argos-external-'.$purpose.'-'.bin2hex(random_bytes(4));
        if (! is_dir($base)) {
            mkdir($base, 0700, true);
        }
        $this->cleanup->push("rm -rf {$base}", fn () => $this->rrmdir($base));

        return $base;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
