<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\GitProvider\GitProviderRegistry;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceProvisioner;
use App\Testing\E2eConnectAccountCommand;
use App\Testing\FakeAnthropicTokenValidator;
use App\Testing\FakeGitService;
use App\Testing\FakeWorkspaceDaemon;
use App\Testing\FakeWorkspaceProvisioner;
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
 *   - AnthropicTokenValidator (done)
 *   - Git provider services (done)
 *   - Workspace daemon + provisioner (done)
 */
class E2eFakeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->isProduction()) {
            throw new RuntimeException('E2eFakeServiceProvider must never boot in production.');
        }

        $this->app->bind(AnthropicTokenValidator::class, fn (): FakeAnthropicTokenValidator => new FakeAnthropicTokenValidator);

        // Replace the git provider registry so every platform resolves to a
        // fake serving canonical repo/branch data — the workspace form dropdowns
        // then fill in without any external API call.
        $this->app->singleton(GitProviderRegistry::class, function (): GitProviderRegistry {
            $registry = new GitProviderRegistry;

            foreach (['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'] as $key => $label) {
                $registry->register(
                    $key,
                    fn (string $token, string $instanceUrl): FakeGitService => new FakeGitService($key, $label),
                );
            }

            return $registry;
        });

        // Workspaces: no containers, and a scripted daemon that feeds events
        // through the real ingest path.
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->app->singleton(WorkspaceProvisioner::class, FakeWorkspaceProvisioner::class);

        // Test-only seeding helper for OAuth-auth runs (the real browser OAuth
        // redirect can't be automated offline). Registered here so it never
        // shows up in a normal/production CLI.
        $this->commands([E2eConnectAccountCommand::class]);
    }
}
