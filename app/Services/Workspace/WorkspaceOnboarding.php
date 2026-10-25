<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\BackingService;
use App\Enums\WorkerSource;
use App\Enums\WorkspaceSetupStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\Environment\EnvAudit;

/**
 * The setup checklist of a workspace: each step with its state and the one
 * action that moves it on. Required steps gate tasks, gates and the demo.
 */
class WorkspaceOnboarding
{
    public function __construct(
        private readonly AgentPool $agents,
        private readonly WorkspaceSetupService $setup,
        private readonly EnvAudit $env,
    ) {}

    /**
     * @return list<array{key: string, done: bool, required: bool, running: bool, label: string, action: string, url: string}>
     */
    public function steps(Workspace $workspace, User $user): array
    {
        $settings = fn (string $tab): string => route('filament.app.resources.workspaces.settings', ['record' => $workspace, 'tab' => $tab]);
        $setupTask = $this->setup->openTask($workspace);
        $missingEnv = $workspace->live_demo_enabled ? $this->env->audit($workspace)['missing'] : [];
        $neededServices = $this->env->servicesNeeded($workspace);
        $servicesOn = array_diff(array_map(fn (BackingService $s): string => $s->value, $neededServices), $workspace->worker_services ?? []) === [];

        $steps = [
            ['agent', $this->agents->usableAgentsFor($user)->isNotEmpty(), true, route('filament.app.account.resources.agents.create')],
            ['stack', $workspace->worker_stack_id !== null || $workspace->worker_source === WorkerSource::Byoi, true, $settings('environment')],
            ...($neededServices !== [] ? [['services', $servicesOn, true, $settings('environment')]] : []),
            ['setup', $workspace->setup_status === WorkspaceSetupStatus::Ready, true, $setupTask !== null
                ? route('filament.app.resources.workspaces.view', ['record' => $workspace, 'task' => $setupTask->slug])
                : $settings('environment')],
            ['demo', (bool) $workspace->live_demo_enabled, false, $settings('environment')],
            ['env', $missingEnv === [], $workspace->live_demo_enabled, $settings('environment')],
            ['tracker', $workspace->taskProviderBindings()->exists(), false, $settings('integrations')],
            ['members', $workspace->members()->count() > 1, false, $settings('members')],
        ];

        return array_map(fn (array $s): array => [
            'key' => $s[0],
            'done' => $s[1],
            'required' => $s[2],
            'running' => $s[0] === 'setup' && ! $s[1] && $setupTask !== null,
            'label' => (string) __('workspaces.onboarding.steps.'.$s[0]),
            'action' => (string) __('workspaces.onboarding.actions.'.$s[0]),
            'url' => $s[3],
        ], $steps);
    }

    /** Whether a required step is still open. */
    public function incomplete(Workspace $workspace, User $user): bool
    {
        return collect($this->steps($workspace, $user))->contains(fn (array $s): bool => $s['required'] && ! $s['done']);
    }
}
