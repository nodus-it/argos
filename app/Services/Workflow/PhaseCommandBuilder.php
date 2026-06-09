<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\WorkflowStatus;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Project\ProjectEnvironmentResolver;
use App\Workers\Agents\MaterializedAgentCredential;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Support\Facades\Log;

/**
 * Builds the `docker run` command for a worker phase, resolving every input it
 * needs — agent, credential, worker image, model, max-turns, resume session.
 * Split out of PhaseRunner so command/config resolution is one concern; the
 * runner asks it for the command and (for persistence) the resolved model/agent.
 */
class PhaseCommandBuilder
{
    /**
     * @param  array<string, mixed>  $flags
     * @return list<string>
     */
    public function build(Task $task, string $phase, array $flags = []): array
    {
        $profile = $task->repoProfile;

        if ($profile === null) {
            Log::channel('argos')->error('Phase cannot start: task has no repo profile', ['task' => $task->name, 'phase' => $phase]);
            throw new \RuntimeException(
                "Task '{$task->name}' hat kein Repo-Profil — Phase kann nicht gestartet werden."
            );
        }

        $agentName = $this->resolveAgentName($task);
        $materializedCredential = $this->materializeCredential($task, $agentName);

        $workerImage = $this->resolveWorkerImage($task);
        $phaseFlags = $flags !== [] ? json_encode($flags) : '{}';

        $maxTurns = $this->resolveMaxTurns($task, $phase, $flags);
        $resumeSessionId = $this->resolveResumeSessionId($task, $phase, $flags);
        $modelId = $this->resolveModel($task, $agentName, $phase);

        $cmd = [
            'docker', 'run', '--rm',
            '-v', $task->volumeName().':/workspace',
            '-v', 'composer_cache:/home/agent/.composer/cache',
            '-v', 'npm_cache:/home/agent/.npm',
            '--memory', (string) config('argos.docker.memory_limit'),
            '--cpus',   (string) config('argos.docker.cpu_limit'),
            '-e', "PHASE={$phase}",
            '-e', "TASK_ID={$task->name}",
            '-e', "REPO_URL={$profile->url}",
            '-e', "REPO_TOKEN={$this->resolveRepoToken($profile)}",
            '-e', "REPO_PLATFORM={$profile->platform->value}",
            '-e', 'BASE_BRANCH='.($task->base_branch ?: $profile->default_branch),
            '-e', "AGENT_NAME={$agentName->value}",
            '-e', 'TASK_DESCRIPTION='.app(UntrustedTaskInput::class)->wrap($task),
            '-e', "PHASE_FLAGS={$phaseFlags}",
            '-e', "MAX_TURNS={$maxTurns}",
            '-e', 'CLAUDE_CONFIG_DIR=/workspace/.agent/claude-state',
            '-e', 'LOG_LEVEL=info',
            '-e', "CLAUDE_MODEL={$modelId}",
            // Deterministic placeholder APP_KEY so the target repo's
            // composer `post-autoload-dump` (which boots Laravel for
            // package:discover) and `php artisan boost:mcp` don't crash on
            // encrypted-cast migrations / providers. We never persist
            // anything in the worker volume, so this key has no security
            // role — it only lets the boot pipeline get through.
            '-e', 'APP_KEY=base64:QXJnb3NXb3JrZXJEdW1teUtleU5vU2VjcmV0c0hlcmU=',
        ];

        foreach ($materializedCredential->envVars as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }

        // Project-level secrets (COMPOSER_AUTH for private registries, plus any
        // raw env the project declared). Reserved Argos keys are already
        // stripped by the resolver, so this can't clobber REPO_TOKEN et al.
        foreach (app(ProjectEnvironmentResolver::class)->resolve($profile) as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = "{$key}={$value}";
        }

        if ($resumeSessionId !== null) {
            $cmd[] = '-e';
            $cmd[] = "RESUME_SESSION_ID={$resumeSessionId}";
        }

        $commitUser = $task->user;
        if ($commitUser !== null) {
            $cmd[] = '-e';
            $cmd[] = "COMMIT_USER_NAME={$commitUser->name}";
            $cmd[] = '-e';
            $cmd[] = "COMMIT_USER_EMAIL={$commitUser->email}";
        }

        if (! empty($flags['force_unlock'])) {
            $cmd[] = '-e';
            $cmd[] = 'FORCE_UNLOCK=1';
        }

        $cmd[] = $workerImage;
        $cmd[] = $phase;
        $cmd[] = $task->name;

        return $cmd;
    }

    /**
     * Resolution order: task override → repo_profile setting → default
     * (claude-code). Mirrors what WorkerImageResolver does for the
     * stack/agent pair so AGENT_NAME stays consistent with the chosen
     * worker image.
     */
    public function resolveAgentName(Task $task): AgentName
    {
        return $task->worker_agent_name_override
            ?? $task->repoProfile?->worker_agent_name
            ?? AgentName::ClaudeCode;
    }

    /**
     * Resolve the model id to send to the worker for this phase, agent-aware.
     *
     * Phase mapping:
     *  - respond: concept-review uses the concept model, code-review the implement model
     *  - commit-message: a dedicated cheap-model slot
     *  - everything else: takes its own slot (concept/implement)
     *
     * Resolution order per slot: task override → repo profile default →
     * agent-spec default for the phase. The env var stays named
     * CLAUDE_MODEL for now because the worker scripts and Claude runner
     * read it as such; the Codex runner ignores it (Codex picks via its
     * own --model arg if needed).
     */
    public function resolveModel(Task $task, AgentName $agentName, string $phase): string
    {
        $effectivePhase = match ($phase) {
            'respond' => $task->workflow_status === WorkflowStatus::ConceptReview ? 'concept' : 'implement',
            default => $phase,
        };

        $taskModel = match ($effectivePhase) {
            'concept' => $task->model_concept,
            'implement' => $task->model_implement,
            default => null,
        };
        if ($taskModel !== null && $taskModel !== '') {
            return $taskModel;
        }

        $profile = $task->repoProfile;
        $profileModel = match ($effectivePhase) {
            'concept' => $profile?->model_concept,
            'implement' => $profile?->model_implement,
            default => null,
        };
        if ($profileModel !== null && $profileModel !== '') {
            return $profileModel;
        }

        $spec = $agentName->spec();
        $default = $spec->defaultModel($effectivePhase);

        return $default ?? '';
    }

    private function resolveRepoToken(RepoProfile $profile): string
    {
        return $profile->resolveToken();
    }

    /**
     * Materialise the agent's credential into env-vars for `docker run`.
     *
     * Resolution order:
     *   1. Explicit `task.agent_credential_id` if set
     *   2. First active AgentCredential for the resolved agent (matches
     *      what TaskResource's helper text promises)
     *   3. Whatever the runner falls back to when handed null — claude
     *      reads the legacy env/file token, codex throws because it has
     *      no shared-secret fallback
     */
    private function materializeCredential(Task $task, AgentName $agentName): MaterializedAgentCredential
    {
        $runner = $agentName->runner();

        $credential = $task->agentCredential
            ?? $this->resolveDefaultCredential($agentName);

        return $runner->materializeCredential($credential);
    }

    /**
     * First active credential for the agent, ordered by created_at so the
     * "first one I made" intuition matches what the form's "Leer = erste
     * aktive Credential" helper text claims.
     */
    private function resolveDefaultCredential(AgentName $agentName): ?AgentCredential
    {
        return AgentCredential::query()
            ->where('agent_name', $agentName->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Resolves the worker image tag via the compose pipeline, building the
     * image on demand if the (stack × agent × version) tuple does not yet
     * exist locally. Fetched via the container so tests can bind a stub.
     */
    private function resolveWorkerImage(Task $task): string
    {
        return app(WorkerImageResolver::class)->resolveOrBuild($task);
    }

    /**
     * Priority: explicit flags['max_turns'] > phase-specific task setting >
     * phase-aware config default. Concept and implement have separate task
     * overrides (`task.max_turns_concept`, `task.max_turns_implement`) so the
     * UI can tune each independently — concept usually needs ~30, implement
     * ~200.
     *
     * @param  array<string, mixed>  $flags
     */
    private function resolveMaxTurns(Task $task, string $phase, array $flags): int
    {
        if (isset($flags['max_turns']) && (int) $flags['max_turns'] > 0) {
            return (int) $flags['max_turns'];
        }

        $taskOverride = $phase === 'concept'
            ? $task->max_turns_concept
            : $task->max_turns_implement;

        if ($taskOverride !== null && $taskOverride > 0) {
            return $taskOverride;
        }

        // Per-project default (task → project → global config), mirroring how
        // modelForPhase resolves the model. Lets large repos raise the budget
        // without bumping the global default for every project.
        $profile = $task->repoProfile;
        if ($profile !== null) {
            $profileOverride = $phase === 'concept'
                ? $profile->max_turns_concept
                : $profile->max_turns_implement;

            if ($profileOverride !== null && $profileOverride > 0) {
                return $profileOverride;
            }
        }

        $configKey = $phase === 'concept'
            ? 'argos.concept.max_turns_default'
            : 'argos.implement.max_turns_default';

        return (int) config($configKey, $phase === 'concept' ? 30 : 200);
    }

    /**
     * For continue-mode runs: find the session_id of the last run of this
     * phase so the worker can call `claude --resume <id>`. Both concept and
     * implement support pause/resume.
     *
     * @param  array<string, mixed>  $flags
     */
    private function resolveResumeSessionId(Task $task, string $phase, array $flags): ?string
    {
        if (! in_array($phase, ['concept', 'implement'], true)) {
            return null;
        }
        if (empty($flags['continue'])) {
            return null;
        }

        $lastRun = $task->phaseRuns()
            ->where('phase', $phase)
            ->orderByDesc('iteration')
            ->first();

        $sessionId = $lastRun?->result_json['claude_session_id'] ?? null;
        if (! is_string($sessionId) || $sessionId === '') {
            // Fallback: parse from stream_log (older runs may not have result_json populated yet)
            $sessionId = $this->extractSessionIdFromStreamLog($lastRun?->stream_log);
        }

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function extractSessionIdFromStreamLog(?string $streamLog): ?string
    {
        if ($streamLog === null || $streamLog === '') {
            return null;
        }
        // The system/init event carries the session_id. It is the first JSON
        // line of the agent stream, but the persisted log now prefixes the
        // worker's orchestration lines — so scan for the first session_id
        // rather than assuming line one.
        foreach (explode("\n", $streamLog) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '"session_id"')) {
                continue;
            }
            $event = json_decode($line, true);
            if (is_array($event) && isset($event['session_id']) && is_string($event['session_id'])) {
                return $event['session_id'];
            }
        }

        return null;
    }
}
