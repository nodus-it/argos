<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\PhaseStatus;
use App\Models\AgentCredential;
use App\Models\ConnectedAccount;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\Anthropic\CredentialStore;
use App\Services\Workflow\PhaseRunner;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PhaseRunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/argos_runner_'.uniqid();
        mkdir($this->tmpDir, 0755, true);
        config([
            'argos.config_dir' => $this->tmpDir,
            'argos.claude_token' => 'test-claude-token',
        ]);

        // PhaseRunner asks the resolver for the worker image tag — bypass the
        // compose pipeline (no docker build, no stack seeding) by binding a
        // stub resolver that returns a fixed tag every test can assert on.
        $resolver = Mockery::mock(WorkerImageResolver::class);
        $resolver->shouldReceive('resolveOrBuild')->andReturn('argos-worker:test');
        $this->app->instance(WorkerImageResolver::class, $resolver);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
        parent::tearDown();
    }

    // --- getPhaseLogPath ---

    public function test_get_phase_log_path_returns_correct_path(): void
    {
        $runner = app(PhaseRunner::class);

        $path = $runner->getPhaseLogPath('my-task', 'concept');

        $this->assertSame("{$this->tmpDir}/tasks/my-task/concept.bg.log", $path);
    }

    // --- runBlocking ---

    public function test_run_blocking_creates_phase_run_in_database(): void
    {
        $task = $this->taskWithProfile();

        $this->runBlockingWithExitCode($task, 'concept', 0);

        $this->assertDatabaseHas(PhaseRun::class, [
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
        ]);
    }

    public function test_run_blocking_sets_task_status_to_running_then_final(): void
    {
        $task = $this->taskWithProfile();

        $this->runBlockingWithExitCode($task, 'concept', 0);

        $this->assertDatabaseHas(Task::class, [
            'id' => $task->id,
            'current_phase' => 'concept',
            'current_status' => 'completed',
        ]);
    }

    public function test_run_blocking_exit_code_0_means_completed(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 0);
        $this->assertSame(PhaseStatus::Completed, PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_exit_code_4_means_quality_gate_failed(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 4);
        $this->assertSame(PhaseStatus::QualityGateFailed, PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_exit_code_5_means_no_changes(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 5);
        $this->assertSame(PhaseStatus::NoChanges, PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_nonzero_exit_code_means_failed(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 1);
        $this->assertSame(PhaseStatus::Failed, PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_increments_iteration_on_second_run(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 0);
        $this->runBlockingWithExitCode($task, 'concept', 0);

        $iterations = PhaseRun::where('task_id', $task->id)->pluck('iteration')->sort()->values();
        $this->assertSame([1, 2], $iterations->all());
    }

    public function test_run_blocking_parses_result_json_cost_and_tokens(): void
    {
        $task = $this->taskWithProfile();
        $resultJson = json_encode([
            'phase' => 'concept',
            'status' => 'completed',
            'claude_total_cost_usd' => '0.0531',
            'input_tokens' => 1500,
            'output_tokens' => 300,
        ]);

        $this->runBlockingWithOutput($task, 'concept', 0, "some output\n{$resultJson}\n");

        $run = PhaseRun::where('task_id', $task->id)->first();
        $this->assertEqualsWithDelta(0.0531, (float) $run->cost_usd, 0.0001);
        $this->assertSame(1500, $run->input_tokens);
        $this->assertSame(300, $run->output_tokens);
    }

    public function test_run_blocking_throws_when_task_has_no_repo_profile(): void
    {
        $task = Task::factory()->create(['repo_profile_id' => null]);
        $runner = app(PhaseRunner::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Repo-Profil/');

        $runner->runBlocking($task, 'concept');
    }

    public function test_run_blocking_throws_when_no_claude_token_configured(): void
    {
        config(['argos.claude_token' => null]);
        $task = $this->taskWithProfile();

        $this->mock(CredentialStore::class)
            ->shouldReceive('getClaudeToken')
            ->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Claude OAuth Token/');

        app(PhaseRunner::class)->runBlocking($task, 'concept');
    }

    public function test_falls_back_to_first_active_credential_when_task_has_none(): void
    {
        config(['argos.claude_token' => null]);
        $this->mock(CredentialStore::class)->shouldReceive('getClaudeToken')->andReturn(null);

        // Two creds, the older active one is what the resolver should pick.
        AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'older active',
            'credentials' => ['token' => 'sk-default-token'],
            'status' => AgentCredentialStatus::Active,
            'created_at' => now()->subHour(),
        ]);
        AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'newer active',
            'credentials' => ['token' => 'sk-newer-token'],
            'status' => AgentCredentialStatus::Active,
            'created_at' => now(),
        ]);

        $task = $this->taskWithProfile();
        $task->update(['agent_credential_id' => null]);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('CLAUDE_CODE_OAUTH_TOKEN=sk-default-token', $cmd);
    }

    public function test_explicit_task_credential_overrides_default_active_credential(): void
    {
        config(['argos.claude_token' => null]);
        $this->mock(CredentialStore::class)->shouldReceive('getClaudeToken')->andReturn(null);

        AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'older active',
            'credentials' => ['token' => 'sk-older-but-default'],
            'status' => AgentCredentialStatus::Active,
            'created_at' => now()->subDay(),
        ]);
        $explicit = AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'name' => 'explicitly chosen',
            'credentials' => ['token' => 'sk-explicit-token'],
            'status' => AgentCredentialStatus::Active,
            'created_at' => now(),
        ]);

        $task = $this->taskWithProfile();
        $task->update(['agent_credential_id' => $explicit->id]);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('CLAUDE_CODE_OAUTH_TOKEN=sk-explicit-token', $cmd);
    }

    public function test_inactive_credentials_are_not_used_as_default(): void
    {
        config(['argos.claude_token' => null]);
        $this->mock(CredentialStore::class)->shouldReceive('getClaudeToken')->andReturn(null);

        // Revoked rows must be skipped — only active ones count.
        AgentCredential::factory()->create([
            'agent_name' => AgentName::ClaudeCode,
            'credentials' => ['token' => 'sk-revoked'],
            'status' => AgentCredentialStatus::Revoked,
            'created_at' => now()->subDay(),
        ]);

        $task = $this->taskWithProfile();
        $task->update(['agent_credential_id' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Claude OAuth Token/');

        app(PhaseRunner::class)->runBlocking($task, 'concept');
    }

    // --- buildCommand (tested indirectly) ---

    public function test_build_command_includes_required_docker_flags(): void
    {
        $task = $this->taskWithProfile();
        $capturedCmd = null;

        $processMock = $this->makeProcessMock(exitCode: 0);
        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock, &$capturedCmd): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')
                ->once()  // capture only the phase command, not postPhaseSync calls
                ->andReturnUsing(function (array $cmd) use ($processMock, &$capturedCmd): Process {
                    $capturedCmd = $cmd;

                    return $processMock;
                });
            $mock->shouldReceive('writeNotesToVolume')->andReturn(null);
            $mock->shouldReceive('postPhaseSync')->andReturn(null);
        });

        $runner->runBlocking($task, 'concept');

        $this->assertContains('docker', $capturedCmd);
        $this->assertContains('run', $capturedCmd);
        $this->assertContains('concept', $capturedCmd);
        $this->assertContains('--rm', $capturedCmd);
        $this->assertContains('PHASE=concept', array_filter($capturedCmd, fn ($v) => str_contains($v, 'PHASE=')));
        $this->assertContains('CLAUDE_CODE_OAUTH_TOKEN=test-claude-token', array_filter($capturedCmd, fn ($v) => str_contains($v, 'CLAUDE_CODE_OAUTH_TOKEN=')));
    }

    // --- max-turns + resume ---

    public function test_build_command_uses_config_default_for_max_turns_when_unset(): void
    {
        config(['argos.implement.max_turns_default' => 250]);
        $task = $this->taskWithProfile();

        $cmd = $this->captureCommand($task, 'implement');

        $this->assertContains('MAX_TURNS=250', $cmd);
    }

    public function test_build_command_uses_task_max_turns_when_set(): void
    {
        config(['argos.implement.max_turns_default' => 200]);
        $task = $this->taskWithProfile();
        $task->update(['max_turns' => 350]);

        $cmd = $this->captureCommand($task, 'implement');

        $this->assertContains('MAX_TURNS=350', $cmd);
    }

    public function test_build_command_uses_explicit_flag_max_turns_over_task_setting(): void
    {
        $task = $this->taskWithProfile();
        $task->update(['max_turns' => 350]);

        $cmd = $this->captureCommand($task, 'implement', ['max_turns' => 500]);

        $this->assertContains('MAX_TURNS=500', $cmd);
    }

    public function test_build_command_always_sets_claude_config_dir(): void
    {
        $task = $this->taskWithProfile();

        $cmd = $this->captureCommand($task, 'implement');

        $this->assertContains('CLAUDE_CONFIG_DIR=/workspace/.agent/claude-state', $cmd);
    }

    public function test_build_command_passes_resume_session_id_on_continue(): void
    {
        $task = $this->taskWithProfile();
        // Simulate a previous paused implement run.
        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'paused',
            'result_json' => ['claude_session_id' => 'abc-123-xyz'],
        ]);

        $cmd = $this->captureCommand($task, 'implement', ['continue' => true]);

        $this->assertContains('RESUME_SESSION_ID=abc-123-xyz', $cmd);
        $this->assertContains('PHASE_FLAGS={"continue":true}', $cmd);
    }

    public function test_build_command_does_not_pass_resume_session_id_without_continue_flag(): void
    {
        $task = $this->taskWithProfile();
        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'paused',
            'result_json' => ['claude_session_id' => 'abc-123'],
        ]);

        $cmd = $this->captureCommand($task, 'implement');

        $this->assertEmpty(array_filter($cmd, fn ($v) => str_starts_with($v, 'RESUME_SESSION_ID=')));
    }

    public function test_extract_stop_reason_returns_subtype_from_last_result_event(): void
    {
        $runner = app(PhaseRunner::class);
        $log = implode("\n", [
            '{"type":"system","subtype":"init"}',
            '{"type":"assistant","message":{}}',
            '{"type":"result","subtype":"error_max_turns","is_error":true,"num_turns":51}',
        ]);

        $reflection = new \ReflectionMethod(PhaseRunner::class, 'extractStopReasonFromStreamLog');
        $reflection->setAccessible(true);
        $stopReason = $reflection->invoke($runner, $log);

        $this->assertSame('error_max_turns', $stopReason);
    }

    public function test_build_command_uses_image_tag_from_resolver(): void
    {
        $task = $this->taskWithProfile();

        $cmd = $this->captureCommand($task, 'concept');

        // Resolver is stubbed in setUp() to always return 'argos-worker:test'.
        $this->assertContains('argos-worker:test', $cmd);
    }

    // --- commit user env vars ---

    public function test_build_command_includes_commit_user_env_vars_when_task_has_user(): void
    {
        $user = User::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $task = $this->taskWithProfile();
        $task->update(['user_id' => $user->id]);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('COMMIT_USER_NAME=Jane Doe', $cmd);
        $this->assertContains('COMMIT_USER_EMAIL=jane@example.com', $cmd);
    }

    public function test_build_command_omits_commit_user_env_vars_when_task_has_no_user(): void
    {
        $task = $this->taskWithProfile();
        $task->update(['user_id' => null]);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertEmpty(array_filter($cmd, fn ($v) => str_starts_with($v, 'COMMIT_USER_NAME=')));
        $this->assertEmpty(array_filter($cmd, fn ($v) => str_starts_with($v, 'COMMIT_USER_EMAIL=')));
    }

    // --- base_branch override ---

    public function test_build_command_uses_profile_default_branch_when_task_base_branch_is_null(): void
    {
        $task = $this->taskWithProfile(['default_branch' => 'develop']);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('BASE_BRANCH=develop', $cmd);
    }

    public function test_build_command_uses_task_base_branch_when_set(): void
    {
        $task = $this->taskWithProfile(['default_branch' => 'main']);
        $task->update(['base_branch' => 'feature/custom-base']);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('BASE_BRANCH=feature/custom-base', $cmd);
        $this->assertNotContains('BASE_BRANCH=main', $cmd);
    }

    public function test_build_command_falls_back_to_profile_branch_when_task_base_branch_is_empty_string(): void
    {
        $task = $this->taskWithProfile(['default_branch' => 'staging']);
        $task->update(['base_branch' => '']);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('BASE_BRANCH=staging', $cmd);
    }

    public function test_extract_stop_reason_returns_null_without_result_event(): void
    {
        $runner = app(PhaseRunner::class);
        $log = '{"type":"system","subtype":"init"}'."\n".'{"type":"assistant","message":{}}';

        $reflection = new \ReflectionMethod(PhaseRunner::class, 'extractStopReasonFromStreamLog');
        $reflection->setAccessible(true);
        $stopReason = $reflection->invoke($runner, $log);

        $this->assertNull($stopReason);
    }

    // --- runBlocking ordering (race-condition fix) ---

    public function test_run_blocking_calls_post_phase_sync_before_setting_final_status(): void
    {
        $task = $this->taskWithProfile();
        $statusDuringPostPhaseSync = 'not-captured';

        $processMock = $this->makeProcessMock(exitCode: 0);

        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock, $task, &$statusDuringPostPhaseSync): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
            $mock->shouldReceive('writeNotesToVolume')->andReturn(null);
            $mock->shouldReceive('postPhaseSync')
                ->once()
                ->andReturnUsing(function () use ($task, &$statusDuringPostPhaseSync): void {
                    // Capture task's current_status in the DB at the moment postPhaseSync runs.
                    $statusDuringPostPhaseSync = $task->fresh()->current_status?->value;
                });
        });

        $runner->runBlocking($task, 'concept');

        // postPhaseSync must be called before current_status is set to 'completed',
        // so content is always in the DB before the UI poll can detect completion.
        $this->assertNotSame('completed', $statusDuringPostPhaseSync,
            'postPhaseSync was called after current_status was already set to completed — race condition still present');

        // After runBlocking returns, current_status must be 'completed'.
        $this->assertSame('completed', $task->fresh()->current_status?->value);
    }

    public function test_run_blocking_uses_phase_run_status_after_paused_promotion(): void
    {
        $task = $this->taskWithProfile();
        $processMock = $this->makeProcessMock(exitCode: 1); // non-zero → Failed initially

        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
            $mock->shouldReceive('writeImplementNotesToVolume')->andReturn(null);
            // Simulate postPhaseSync promoting Failed → Paused (as it does for error_max_turns)
            $mock->shouldReceive('postPhaseSync')
                ->once()
                ->andReturnUsing(function (Task $t, PhaseRun $pr): void {
                    $pr->update(['status' => PhaseStatus::Paused]);
                    $t->update(['current_status' => PhaseStatus::Paused]);
                });
        });

        $runner->runBlocking($task, 'implement');

        // Final task status must be Paused (promoted by postPhaseSync), not Failed.
        $this->assertSame('paused', $task->fresh()->current_status?->value);
    }

    // --- postPhaseSync error_log capture ---

    public function test_post_phase_sync_captures_clone_err_when_concept_failed(): void
    {
        $task = $this->taskWithProfile();
        $phaseRun = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'failed',
        ]);

        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('readFileFromVolume')
                ->andReturnUsing(function (string $volume, string $path): ?string {
                    return match ($path) {
                        '/workspace/.agent/concept.md' => null,
                        '/workspace/.agent/state.json' => null,
                        '/workspace/.agent/logs/clone.err' => "fatal: couldn't find remote ref main\n",
                        default => null,
                    };
                });
        });

        $runner->postPhaseSync($task, $phaseRun, 'concept', null);

        $this->assertSame("fatal: couldn't find remote ref main\n", $phaseRun->fresh()->error_log);
    }

    public function test_post_phase_sync_does_not_set_error_log_on_completed_concept(): void
    {
        $task = $this->taskWithProfile();
        $phaseRun = PhaseRun::create([
            'task_id' => $task->id,
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
        ]);

        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('readFileFromVolume')
                ->andReturnUsing(function (string $volume, string $path): ?string {
                    return match ($path) {
                        '/workspace/.agent/concept.md' => "# Konzept\n\nInhalt.",
                        default => null,
                    };
                });
        });

        $runner->postPhaseSync($task, $phaseRun, 'concept', null);

        $this->assertNull($phaseRun->fresh()->error_log);
    }

    // --- resolveRepoToken / per-profile token resolution ---

    public function test_build_command_uses_pat_token_when_auth_method_is_pat(): void
    {
        $task = $this->taskWithProfile(['auth_method' => 'pat', 'token' => 'my-secret-pat']);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('REPO_TOKEN=my-secret-pat', $cmd);
    }

    public function test_build_command_uses_connected_account_token_when_auth_method_is_oauth(): void
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'github',
            'token' => 'oauth-token-from-account',
        ]);

        $profile = RepoProfile::factory()->create([
            'url' => 'https://github.com/org/repo',
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => $account->id,
            'token' => null,
        ]);

        $task = Task::factory()->create([
            'name' => 'oauth-task',
            'repo_profile_id' => $profile->id,
        ]);

        $cmd = $this->captureCommand($task, 'concept');

        $this->assertContains('REPO_TOKEN=oauth-token-from-account', $cmd);
    }

    public function test_build_command_throws_when_auth_method_is_pat_but_token_missing(): void
    {
        $profile = RepoProfile::factory()->create([
            'url' => 'https://github.com/org/repo',
            'platform' => 'github',
            'auth_method' => 'pat',
            'token' => null,
        ]);

        $task = Task::factory()->create([
            'name' => 'no-token-task',
            'repo_profile_id' => $profile->id,
        ]);

        $runner = app(PhaseRunner::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PAT/');

        $runner->runBlocking($task, 'concept');
    }

    public function test_build_command_throws_when_auth_method_is_oauth_but_no_account_linked(): void
    {
        $profile = RepoProfile::factory()->create([
            'url' => 'https://github.com/org/repo',
            'platform' => 'github',
            'auth_method' => 'oauth',
            'connected_account_id' => null,
            'token' => null,
        ]);

        $task = Task::factory()->create([
            'name' => 'no-account-task',
            'repo_profile_id' => $profile->id,
        ]);

        $runner = app(PhaseRunner::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OAuth/');

        $runner->runBlocking($task, 'concept');
    }

    // --- helpers ---

    /**
     * Capture the docker command that PhaseRunner::buildCommand produces by
     * running runBlocking with all I/O fully mocked.
     *
     * @param  array<string, mixed>  $flags
     * @return array<int, string>
     */
    private function captureCommand(Task $task, string $phase, array $flags = []): array
    {
        $captured = [];
        $processMock = $this->makeProcessMock(exitCode: 0);

        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock, &$captured): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')
                ->once()
                ->andReturnUsing(function (array $cmd) use ($processMock, &$captured): Process {
                    $captured = $cmd;

                    return $processMock;
                });
            $mock->shouldReceive('writeNotesToVolume')->andReturn(null);
            $mock->shouldReceive('writeImplementNotesToVolume')->andReturn(null);
            $mock->shouldReceive('postPhaseSync')->andReturn(null);
        });

        $runner->runBlocking($task, $phase, $flags);

        return $captured;
    }

    /**
     * @param  array<string, mixed>  $profileAttributes
     */
    private function taskWithProfile(array $profileAttributes = []): Task
    {
        $profile = RepoProfile::factory()->create(array_merge([
            'url' => 'https://github.com/org/repo',
            'default_branch' => 'main',
            'auto_pr' => false,
        ], $profileAttributes));

        return Task::factory()->create([
            'name' => 'test-task',
            'repo_profile_id' => $profile->id,
            'description' => 'Test description',
        ]);
    }

    private function makeProcessMock(int $exitCode, string $stdout = ''): Process
    {
        $mock = Mockery::mock(Process::class);
        $mock->shouldReceive('setTimeout')->andReturnSelf();
        $mock->shouldReceive('setIdleTimeout')->andReturnSelf();
        $mock->shouldReceive('run')
            ->andReturnUsing(function (?callable $callback = null) use ($stdout): int {
                if ($callback !== null && $stdout !== '') {
                    $callback(Process::OUT, $stdout);
                }

                return 0;
            });
        $mock->shouldReceive('getExitCode')->andReturn($exitCode);
        $mock->shouldReceive('isSuccessful')->andReturn(true);
        $mock->shouldReceive('getOutput')->andReturn('');

        return $mock;
    }

    private function runBlockingWithExitCode(Task $task, string $phase, int $exitCode): void
    {
        $this->runBlockingWithOutput($task, $phase, $exitCode, '');
    }

    private function runBlockingWithOutput(Task $task, string $phase, int $exitCode, string $stdout): void
    {
        $processMock = $this->makeProcessMock($exitCode, $stdout);

        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')->andReturn($processMock);
            // Isolate from postPhaseSync Docker calls in unit tests
            $mock->shouldReceive('postPhaseSync')->andReturn(null);
            $mock->shouldReceive('writeNotesToVolume')->andReturn(null);
        });

        $runner->runBlocking($task, $phase);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $this->rmdirRecursive($item) : unlink($item);
        }
        rmdir($dir);
    }
}
