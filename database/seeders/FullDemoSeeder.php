<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\WorkerSource;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\WorkerStack;
use App\Models\Workspace;
use Database\Seeders\Support\CredentialMatrixBuilder;
use Database\Seeders\Support\DemoUserBuilder;
use Database\Seeders\Support\ProviderMatrixBuilder;
use Database\Seeders\Support\WorkerStackBuilder;
use Database\Seeders\Support\WorkspaceSeeds;
use Illuminate\Database\Seeder;

/**
 * Full-Demo profile: populate every admin view with all conceivable variants,
 * using meaningful object names that show what each one represents. Composes
 * the shared builders:
 *
 *   - the full git/issue provider matrix                          — ProviderMatrixBuilder
 *   - agent + provider credentials in every status                — CredentialMatrixBuilder
 *   - a built-in "update available" stack + a custom BYOI stack   — WorkerStackBuilder
 *   - workspace feature variants (live-demo, BYOI)
 *   - one external issue link on a seeded binding                 — ExternalIssueLink
 *
 * Idempotent — re-seeding updates in place. Run via `composer dev:full`
 * (→ .tools/bin/dev-reset.sh full) or `php artisan db:seed --class=FullDemoSeeder`.
 */
final class FullDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = (new DemoUserBuilder($this->command))->adminUser();

        (new CredentialMatrixBuilder)->agentCredentialMatrix();
        (new CredentialMatrixBuilder)->providerCredentialMatrix();

        $profiles = (new ProviderMatrixBuilder($this->command))->build($user);

        $stacks = new WorkerStackBuilder;
        $stacks->flagBuiltinHasUpdate();
        $byoiStack = $stacks->customByoiStack($user);

        $this->showcaseRepo($user);
        $this->workspaceVariants($user, $byoiStack);
        $this->externalIssue($profiles['github']);

        $this->command?->info('Full-Demo profile seeded: providers, credentials, stacks, workspace variants and an issue link.');
    }

    /** A plain OAuth workspace to work in. */
    private function showcaseRepo(User $user): Workspace
    {
        return WorkspaceSeeds::upsert($user, 'showcase', [
            'repo_url' => 'https://github.com/nodus-it/argos-showcase.git',
            'platform' => GitProvider::GitHub->value,
            'auth_method' => AuthMethod::OAuth->value,
            'default_branch' => 'main',
        ]);
    }

    /** Feature-flag variants so the workspace list/form shows each toggle in action. */
    private function workspaceVariants(User $user, WorkerStack $byoiStack): void
    {
        $this->variant($user, 'demo · live-demo', GitProvider::GitHub, ['live_demo_enabled' => true]);
        $this->variant($user, 'demo · BYOI stack', GitProvider::GitHub, [
            'worker_source' => WorkerSource::Byoi->value,
            'worker_stack_id' => $byoiStack->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function variant(User $user, string $name, GitProvider $platform, array $extra): void
    {
        WorkspaceSeeds::upsert(
            $user,
            $name,
            [
                'repo_url' => 'https://github.com/nodus-it/'.str($name)->slug().'.git',
                'platform' => $platform->value,
                'auth_method' => AuthMethod::Pat->value,
                'token' => 'pat-demo',
                'default_branch' => 'main',
                ...$extra,
            ],
        );
    }

    /** One external issue link on a real seeded binding (not launched yet). */
    private function externalIssue(?Workspace $githubProfile): void
    {
        if ($githubProfile === null) {
            return;
        }

        $binding = TaskProviderBinding::where('workspace_id', $githubProfile->id)
            ->where('kind', TaskProviderKind::GitHub->value)
            ->where('mode', TaskProviderMode::Webhook->value)
            ->first();

        if ($binding === null) {
            return;
        }

        ExternalIssueLink::updateOrCreate(
            ['task_provider_binding_id' => $binding->id, 'external_id' => '4242'],
            [
                'external_url' => 'https://github.com/nodus-it/argos-test/issues/4242',
                'last_synced_at' => now(),
            ],
        );
    }
}
