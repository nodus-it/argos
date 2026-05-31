<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Workflow\PhaseRunner;
use Mockery;
use Symfony\Component\Process\Process;

/**
 * Builder for a fake worker Process + PhaseRunner partial-mock. Replaces the
 * ~12-line per-test Mockery boilerplate with a declarative API:
 *
 *   FakeWorkerProcess::success()->bind($this);
 *   FakeWorkerProcess::failure()->withStdout('boom')->bind($this);
 *   FakeWorkerProcess::rateLimited()->bind($this);
 *
 * Exit-Code → PhaseStatus (matches PhaseRunner::exitCodeToStatus):
 *   0 → Completed   4 → QualityGateFailed   5 → NoChanges
 *   6 → LockBlocked 7 → RateLimited         else → Failed
 *
 * Each bind() call replaces the previous partial-mock, so sequential phase
 * runs in one test simply re-bind a new outcome per phase.
 */
final class FakeWorkerProcess
{
    private int $exitCode = 0;

    private string $stdout = '';

    private function __construct() {}

    public static function success(): self
    {
        return new self;
    }

    public static function failure(int $exitCode = 1): self
    {
        $instance = new self;
        $instance->exitCode = $exitCode;

        return $instance;
    }

    public static function qualityGateFailure(): self
    {
        return self::failure(4);
    }

    public static function noChanges(): self
    {
        return self::failure(5);
    }

    public static function lockBlocked(): self
    {
        return self::failure(6);
    }

    public static function rateLimited(): self
    {
        return self::failure(7);
    }

    public function withStdout(string $stdout): self
    {
        $this->stdout = $stdout;

        return $this;
    }

    public function bind(): void
    {
        $exitCode = $this->exitCode;
        $stdout = $this->stdout;

        $processMock = Mockery::mock(Process::class);
        $processMock->shouldReceive('setTimeout')->andReturnSelf();
        $processMock->shouldReceive('setIdleTimeout')->andReturnSelf();
        $processMock->shouldReceive('setInput')->andReturnSelf();
        $processMock->shouldReceive('setEnv')->andReturnSelf();
        $processMock->shouldReceive('mustRun')->andReturnSelf();
        $processMock->shouldReceive('run')->andReturnUsing(function (?callable $callback = null) use ($exitCode, $stdout): int {
            if ($callback !== null && $stdout !== '') {
                $callback(Process::OUT, $stdout);
            }

            return $exitCode;
        });
        $processMock->shouldReceive('start')->andReturnNull();
        $processMock->shouldReceive('isRunning')->andReturn(false);
        $processMock->shouldReceive('getExitCode')->andReturn($exitCode);
        $processMock->shouldReceive('getOutput')->andReturn($stdout);
        $processMock->shouldReceive('getIncrementalOutput')->andReturn('');
        $processMock->shouldReceive('wait')->andReturnUsing(fn (?callable $callback = null): int => $exitCode);

        $phaseRunnerMock = Mockery::mock(PhaseRunner::class)->makePartial();
        $phaseRunnerMock->shouldAllowMockingProtectedMethods();
        $phaseRunnerMock->shouldReceive('newProcess')->andReturn($processMock);
        $phaseRunnerMock->shouldReceive('writeNotesToVolume')->andReturn(null);
        $phaseRunnerMock->shouldReceive('postPhaseSync')->andReturn(null);
        // The rate-limit branch (exit 7) reads a file from the worker volume
        // via a second docker process — short-circuit it so we don't have to
        // mock the volume-read process separately. readUsageLimitResetAt is
        // private and not mockable; the underlying readFileFromVolume is
        // protected, so we mock that.
        $phaseRunnerMock->shouldReceive('readFileFromVolume')->andReturn(null);
        $phaseRunnerMock->shouldReceive('storeUsageLimit')->andReturn(null);

        app()->instance(PhaseRunner::class, $phaseRunnerMock);
    }
}
