<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Credentials\CredentialStore;
use App\Domain\Phase\PhaseRunner;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'argos.worker_image' => 'argos-worker:test',
        ]);
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
        $this->assertSame('completed', PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_exit_code_4_means_quality_gate_failed(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 4);
        $this->assertSame('quality_gate_failed', PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_exit_code_5_means_no_changes(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 5);
        $this->assertSame('no_changes', PhaseRun::where('task_id', $task->id)->first()->status);
    }

    public function test_run_blocking_nonzero_exit_code_means_failed(): void
    {
        $task = $this->taskWithProfile();
        $this->runBlockingWithExitCode($task, 'concept', 1);
        $this->assertSame('failed', PhaseRun::where('task_id', $task->id)->first()->status);
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

    // --- buildCommand (tested indirectly) ---

    public function test_build_command_includes_required_docker_flags(): void
    {
        $task = $this->taskWithProfile();
        $capturedCmd = null;

        $processMock = $this->makeProcessMock(exitCode: 0);
        $runner = $this->partialMock(PhaseRunner::class, function (MockInterface $mock) use ($processMock, &$capturedCmd): void {
            $mock->shouldAllowMockingProtectedMethods();
            $mock->shouldReceive('newProcess')
                ->andReturnUsing(function (array $cmd) use ($processMock, &$capturedCmd): Process {
                    $capturedCmd = $cmd;

                    return $processMock;
                });
        });

        $runner->runBlocking($task, 'concept');

        $this->assertContains('docker', $capturedCmd);
        $this->assertContains('run', $capturedCmd);
        $this->assertContains('concept', $capturedCmd);
        $this->assertContains('--rm', $capturedCmd);
        $this->assertContains('PHASE=concept', array_filter($capturedCmd, fn ($v) => str_contains($v, 'PHASE=')));
        $this->assertContains('CLAUDE_CODE_OAUTH_TOKEN=test-claude-token', array_filter($capturedCmd, fn ($v) => str_contains($v, 'CLAUDE_CODE_OAUTH_TOKEN=')));
    }

    // --- helpers ---

    private function taskWithProfile(): Task
    {
        $profile = RepoProfile::factory()->create([
            'url' => 'https://github.com/org/repo',
            'default_branch' => 'main',
            'auto_pr' => false,
        ]);

        return Task::factory()->create([
            'name' => 'test-task',
            'repo_profile_id' => $profile->id,
            'description' => 'Test description',
        ]);
    }

    private function makeProcessMock(int $exitCode, string $stdout = ''): Process
    {
        $mock = \Mockery::mock(Process::class);
        $mock->shouldReceive('setTimeout')->andReturnSelf();
        $mock->shouldReceive('setIdleTimeout')->andReturnSelf();
        $mock->shouldReceive('run')
            ->andReturnUsing(function (callable $callback) use ($stdout): int {
                if ($stdout !== '') {
                    $callback(Process::OUT, $stdout);
                }

                return 0;
            });
        $mock->shouldReceive('getExitCode')->andReturn($exitCode);

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
