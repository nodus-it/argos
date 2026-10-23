<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Integrations\WorkspaceDaemon\Requests\CreateCheckpoint;
use App\Integrations\WorkspaceDaemon\Requests\GetCheckpointDiff;
use App\Integrations\WorkspaceDaemon\Requests\GetFileContent;
use App\Integrations\WorkspaceDaemon\Requests\GetGateFixPrompt;
use App\Integrations\WorkspaceDaemon\Requests\GetGateLog;
use App\Integrations\WorkspaceDaemon\Requests\GitChanges;
use App\Integrations\WorkspaceDaemon\Requests\GitCommit;
use App\Integrations\WorkspaceDaemon\Requests\GitDiff;
use App\Integrations\WorkspaceDaemon\Requests\GitPush;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Integrations\WorkspaceDaemon\Requests\ListFiles;
use App\Integrations\WorkspaceDaemon\Requests\PrepareWorktree;
use App\Integrations\WorkspaceDaemon\Requests\RemoveWorktree;
use App\Integrations\WorkspaceDaemon\Requests\RestoreCheckpoint;
use App\Integrations\WorkspaceDaemon\Requests\RunGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Request;

/** Worktree-scoped daemon calls address the task's worktree by its slug. */
class WorktreeRequestsTest extends TestCase
{
    /** @return array<string, array{0: Request, 1: string, 2: string}> */
    public static function requests(): array
    {
        return [
            'prepare' => [new PrepareWorktree('login', []), 'POST', '/worktrees/login/prepare'],
            'remove' => [new RemoveWorktree('login'), 'DELETE', '/worktrees/login'],
            'status' => [new GitStatus('login'), 'GET', '/worktrees/login/git/status'],
            'diff' => [new GitDiff('login', 'origin/main'), 'GET', '/worktrees/login/git/diff'],
            'changes' => [new GitChanges('login', 'origin/main'), 'GET', '/worktrees/login/git/changes'],
            'commit' => [new GitCommit('login', []), 'POST', '/worktrees/login/git/commit'],
            'push' => [new GitPush('login', []), 'POST', '/worktrees/login/git/push'],
            'files' => [new ListFiles('login', 'x'), 'GET', '/worktrees/login/files'],
            'file content' => [new GetFileContent('login', 'README.md'), 'GET', '/worktrees/login/files/content'],
            'gates' => [new RunGates('login', []), 'POST', '/worktrees/login/gates/run'],
            'gate fix prompt' => [new GetGateFixPrompt('login', 'pest', 'r1'), 'GET', '/worktrees/login/gates/fix-prompt'],
            'gate log' => [new GetGateLog('login', 'pest', 'r1'), 'GET', '/worktrees/login/gates/log'],
            'checkpoint' => [new CreateCheckpoint('login', []), 'POST', '/worktrees/login/checkpoints'],
            'checkpoint diff' => [new GetCheckpointDiff('login', 'refs/x'), 'GET', '/worktrees/login/checkpoints/diff'],
            'restore' => [new RestoreCheckpoint('login', []), 'POST', '/worktrees/login/checkpoints/restore'],
            'escaped slug' => [new GitStatus('a b'), 'GET', '/worktrees/a%20b/git/status'],
        ];
    }

    #[DataProvider('requests')]
    public function test_the_request_targets_the_worktree(Request $request, string $method, string $endpoint): void
    {
        $this->assertSame($method, $request->getMethod()->value);
        $this->assertSame($endpoint, $request->resolveEndpoint());
    }

    public function test_the_gate_log_request_names_gate_and_run(): void
    {
        $this->assertSame(['gate' => 'phpstan', 'run_id' => 'r9'], (new GetGateLog('login', 'phpstan', 'r9'))->query()->all());
    }
}
