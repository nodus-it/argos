<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\WorkerSource;
use App\Enums\WorkflowStatus;
use App\Models\ExternalIssueLink;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\WorkerStack;
use Database\Seeders\Support\CredentialMatrixBuilder;
use Database\Seeders\Support\DemoDeploymentBuilder;
use Database\Seeders\Support\DemoUserBuilder;
use Database\Seeders\Support\ProviderMatrixBuilder;
use Database\Seeders\Support\WorkerStackBuilder;
use Database\Seeders\Support\WorkflowStageBuilder;
use Illuminate\Database\Seeder;

/**
 * Full-Demo profile: populate every view — above all the task detail — with all
 * conceivable variants, using meaningful object names that show what each one
 * represents. Composes the shared builders:
 *
 *   - one task per TaskStage (banner/stepper/dock states)        — WorkflowStageBuilder
 *   - the full git/issue provider matrix                          — ProviderMatrixBuilder
 *   - agent + provider credentials in every status                — CredentialMatrixBuilder
 *   - a built-in "update available" stack + a custom BYOI stack   — WorkerStackBuilder
 *   - one live-demo deployment per DemoStatus                      — DemoDeploymentBuilder
 *   - RepoProfile feature variants (auto-concept/PR, live-demo, BYOI, devcontainer)
 *   - one task imported from an external issue                     — ExternalIssueLink
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

        $showcaseRepo = $this->showcaseRepo();
        (new WorkflowStageBuilder)->buildAll($user, $showcaseRepo);

        $this->repoProfileVariants($byoiStack);
        $this->demoDeployments($user, $showcaseRepo);
        $this->importedIssueTask($user, $profiles['github']);

        $this->command?->info('Full-Demo profile seeded: stages, providers, credentials, stacks, demos and an imported issue.');
    }

    /** The dedicated repo the per-stage showcase tasks hang off. */
    private function showcaseRepo(): RepoProfile
    {
        return RepoProfile::firstOrCreate(
            ['url' => 'https://github.com/nodus-it/argos-showcase.git'],
            [
                'name' => 'showcase',
                'platform' => GitProvider::GitHub->value,
                'auth_method' => AuthMethod::OAuth->value,
                'default_branch' => 'main',
                'auto_concept' => false,
                'auto_pr' => false,
            ],
        );
    }

    /** Feature-flag variants so the repo list/form shows each toggle in action. */
    private function repoProfileVariants(WorkerStack $byoiStack): void
    {
        $this->repoVariant('demo · auto-concept', GitProvider::GitHub, ['auto_concept' => true]);
        $this->repoVariant('demo · auto-pr', GitProvider::GitHub, ['auto_pr' => true]);
        $this->repoVariant('demo · live-demo', GitProvider::GitHub, ['live_demo_enabled' => true]);
        $this->repoVariant('demo · BYOI stack', GitProvider::GitHub, [
            'worker_source' => WorkerSource::Byoi->value,
            'worker_stack_id' => $byoiStack->id,
        ]);
        $this->repoVariant('demo · devcontainer', GitProvider::GitLab, [
            'worker_source' => WorkerSource::Devcontainer->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function repoVariant(string $name, GitProvider $platform, array $extra): void
    {
        RepoProfile::updateOrCreate(
            ['name' => $name],
            [
                'url' => 'https://github.com/nodus-it/'.str($name)->slug().'.git',
                'platform' => $platform->value,
                'auth_method' => AuthMethod::Pat->value,
                'token' => 'pat-demo',
                'default_branch' => 'main',
                'auto_concept' => false,
                'auto_pr' => false,
                ...$extra,
            ],
        );
    }

    /** Four named tasks, each carrying a live-demo deployment in a distinct state. */
    private function demoDeployments(User $user, RepoProfile $repo): void
    {
        $building = $this->demoTask($user, $repo, 'Demo · Building deployment');
        $live = $this->demoTask($user, $repo, 'Demo · Live deployment');
        $failed = $this->demoTask($user, $repo, 'Demo · Failed deployment');
        $stopped = $this->demoTask($user, $repo, 'Demo · Stopped deployment');

        (new DemoDeploymentBuilder)->attachAllStatuses($building, $live, $failed, $stopped);
    }

    private function demoTask(User $user, RepoProfile $repo, string $name): Task
    {
        return Task::firstOrCreate(
            ['user_id' => $user->id, 'name' => $name],
            [
                'repo_profile_id' => $repo->id,
                'description' => 'Carries a live-demo deployment for the demo panel.',
                'base_branch' => $repo->default_branch,
                'feature_branch' => 'argos/'.str($name)->slug(),
                'workflow_status' => WorkflowStatus::InReview->value,
            ],
        );
    }

    /** One task imported from an external issue, linked to a real seeded binding. */
    private function importedIssueTask(User $user, ?RepoProfile $githubProfile): void
    {
        if ($githubProfile === null) {
            return;
        }

        $binding = TaskProviderBinding::where('repo_profile_id', $githubProfile->id)
            ->where('kind', TaskProviderKind::GitHub->value)
            ->where('mode', TaskProviderMode::Webhook->value)
            ->first();

        if ($binding === null) {
            return;
        }

        $task = Task::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Imported · GitHub issue #4242'],
            [
                'repo_profile_id' => $githubProfile->id,
                'description' => 'Imported from an external GitHub issue by the provider integration.',
                'base_branch' => $githubProfile->default_branch,
                'workflow_status' => WorkflowStatus::Draft->value,
            ],
        );

        ExternalIssueLink::updateOrCreate(
            ['task_provider_binding_id' => $binding->id, 'external_id' => '4242'],
            [
                'task_id' => $task->id,
                'task_imported_at' => now()->subHours(2),
                'external_url' => 'https://github.com/nodus-it/argos-test/issues/4242',
                'last_synced_at' => now(),
            ],
        );
    }
}
