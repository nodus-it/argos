<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon;

use App\Integrations\WorkspaceDaemon\Requests\CreateCheckpoint;
use App\Integrations\WorkspaceDaemon\Requests\GetCheckpointDiff;
use App\Integrations\WorkspaceDaemon\Requests\GetFileContent;
use App\Integrations\WorkspaceDaemon\Requests\GetGateFixPrompt;
use App\Integrations\WorkspaceDaemon\Requests\GetGateLog;
use App\Integrations\WorkspaceDaemon\Requests\GetHealth;
use App\Integrations\WorkspaceDaemon\Requests\GitChanges;
use App\Integrations\WorkspaceDaemon\Requests\GitCommit;
use App\Integrations\WorkspaceDaemon\Requests\GitDiff;
use App\Integrations\WorkspaceDaemon\Requests\GitFetch;
use App\Integrations\WorkspaceDaemon\Requests\GitPush;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Integrations\WorkspaceDaemon\Requests\InitWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\InterruptTask;
use App\Integrations\WorkspaceDaemon\Requests\ListFiles;
use App\Integrations\WorkspaceDaemon\Requests\PrepareWorktree;
use App\Integrations\WorkspaceDaemon\Requests\RemoveWorktree;
use App\Integrations\WorkspaceDaemon\Requests\ResolveApproval;
use App\Integrations\WorkspaceDaemon\Requests\RestoreCheckpoint;
use App\Integrations\WorkspaceDaemon\Requests\RunGates;
use App\Integrations\WorkspaceDaemon\Requests\RunOneshot;
use App\Integrations\WorkspaceDaemon\Requests\SendMessage;
use App\Integrations\WorkspaceDaemon\Requests\SetModel;
use App\Integrations\WorkspaceDaemon\Requests\SetPermissionMode;
use App\Integrations\WorkspaceDaemon\Requests\StartTask;
use App\Integrations\WorkspaceDaemon\Requests\StopTask;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;

/**
 * Typed entry point to one workspace daemon. Translates Saloon failures into
 * DaemonTransportException so callers outside App\Integrations never touch
 * Saloon types.
 */
class WorkspaceDaemonApi implements WorkspaceDaemonTransport
{
    private readonly WorkspaceDaemonConnector $connector;

    public function __construct(string $baseUrl, string $token, int $timeoutSeconds = 15)
    {
        $this->connector = new WorkspaceDaemonConnector($baseUrl, $token, $timeoutSeconds);
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return $this->call(new GetHealth);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function initWorkspace(array $payload): array
    {
        return $this->call(new InitWorkspace($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function startTask(string $taskId, array $payload): array
    {
        return $this->call(new StartTask($taskId, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(string $taskId, array $payload): array
    {
        return $this->call(new SendMessage($taskId, $payload));
    }

    public function interrupt(string $taskId): void
    {
        $this->call(new InterruptTask($taskId));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveApproval(string $taskId, string $approvalId, array $payload): void
    {
        $this->call(new ResolveApproval($taskId, $approvalId, $payload));
    }

    public function setPermissionMode(string $taskId, string $mode): void
    {
        $this->call(new SetPermissionMode($taskId, ['mode' => $mode]));
    }

    public function setModel(string $taskId, string $model): void
    {
        $this->call(new SetModel($taskId, ['model' => $model]));
    }

    public function stopTask(string $taskId): void
    {
        $this->call(new StopTask($taskId));
    }

    public function prepareWorktree(string $worktree, array $payload): void
    {
        $this->call(new PrepareWorktree($worktree, $payload));
    }

    public function removeWorktree(string $worktree): void
    {
        $this->call(new RemoveWorktree($worktree));
    }

    public function gitStatus(string $worktree): array
    {
        /** @var array{branch: string|null, upstream: string|null, ahead: int, behind: int, dirty: bool, files: list<array{path: string, status: string}>} */
        return $this->call(new GitStatus($worktree)) + ['branch' => null, 'upstream' => null, 'ahead' => 0, 'behind' => 0, 'dirty' => false, 'files' => []];
    }

    public function gitDiff(string $worktree, string $base, ?string $path = null): array
    {
        $result = $this->call(new GitDiff($worktree, $base, $path));

        return ['diff' => (string) ($result['diff'] ?? ''), 'truncated' => (bool) ($result['truncated'] ?? false)];
    }

    public function gitFetch(): void
    {
        $this->call(new GitFetch);
    }

    public function gitCommit(string $worktree, string $subject, string $body, array $author): ?array
    {
        try {
            $result = $this->call(new GitCommit($worktree, ['subject' => $subject, 'body' => $body, 'author' => $author]));
        } catch (DaemonTransportException $e) {
            if ($e->errorCode === 'no_changes') {
                return null;
            }
            throw $e;
        }

        return ['sha' => (string) ($result['sha'] ?? '')];
    }

    public function gitPush(string $worktree, string $branch): void
    {
        $this->call(new GitPush($worktree, ['branch' => $branch]));
    }

    public function oneshot(array $payload): array
    {
        $result = $this->call(new RunOneshot($payload));

        return ['text' => (string) ($result['text'] ?? ''), 'structured' => $result['structured'] ?? null, 'cost_usd' => $result['cost_usd'] ?? 0];
    }

    public function files(string $worktree, string $query, int $limit = 20): array
    {
        $files = $this->call(new ListFiles($worktree, $query, $limit))['files'] ?? [];

        return array_values(array_filter(is_array($files) ? $files : [], 'is_string'));
    }

    public function gitChanges(string $worktree, string $base): array
    {
        $files = $this->call(new GitChanges($worktree, $base))['files'] ?? [];

        return array_values(array_map(fn (array $f): array => [
            'path' => (string) ($f['path'] ?? ''),
            'from_path' => isset($f['from_path']) ? (string) $f['from_path'] : null,
            'status' => (string) ($f['status'] ?? 'M'),
            'additions' => (int) ($f['additions'] ?? 0),
            'deletions' => (int) ($f['deletions'] ?? 0),
            'binary' => (bool) ($f['binary'] ?? false),
            'generated' => (bool) ($f['generated'] ?? false),
            'hash' => (string) ($f['hash'] ?? ''),
        ], array_filter(is_array($files) ? $files : [], 'is_array')));
    }

    public function fileContent(string $worktree, string $path): array
    {
        $data = $this->call(new GetFileContent($worktree, $path));

        return ['content' => (string) ($data['content'] ?? ''), 'truncated' => (bool) ($data['truncated'] ?? false)];
    }

    public function runGates(string $worktree, string $runId, ?string $baseCommit): void
    {
        $this->call(new RunGates($worktree, ['run_id' => $runId, 'base_commit' => $baseCommit]));
    }

    public function gateFixPrompt(string $worktree, string $gate, string $runId): string
    {
        return (string) ($this->call(new GetGateFixPrompt($worktree, $gate, $runId))['prompt'] ?? '');
    }

    public function gateLog(string $worktree, string $gate, string $runId): array
    {
        $body = $this->call(new GetGateLog($worktree, $gate, $runId));

        return ['log' => (string) ($body['log'] ?? ''), 'truncated' => (bool) ($body['truncated'] ?? false)];
    }

    public function createCheckpoint(string $worktree, string $taskId, string $label): array
    {
        return self::checkpoint($this->call(new CreateCheckpoint($worktree, ['task_id' => $taskId, 'label' => $label])));
    }

    public function checkpointDiff(string $worktree, string $ref): array
    {
        $result = $this->call(new GetCheckpointDiff($worktree, $ref));

        return ['diff' => (string) ($result['diff'] ?? ''), 'truncated' => (bool) ($result['truncated'] ?? false), 'head' => is_string($result['head'] ?? null) ? $result['head'] : null];
    }

    public function restoreCheckpoint(string $worktree, string $ref): array
    {
        $result = $this->call(new RestoreCheckpoint($worktree, ['ref' => $ref]));
        $removed = is_array($result['removed'] ?? null) ? $result['removed'] : [];

        return [
            'before' => self::checkpoint(is_array($result['before'] ?? null) ? $result['before'] : []),
            'removed' => array_values(array_filter($removed, 'is_string')),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{ref: string, n: int, sha: string, head: string|null, label: string}
     */
    private static function checkpoint(array $raw): array
    {
        return [
            'ref' => (string) ($raw['ref'] ?? ''),
            'n' => (int) ($raw['n'] ?? 0),
            'sha' => (string) ($raw['sha'] ?? ''),
            'head' => is_string($raw['head'] ?? null) ? $raw['head'] : null,
            'label' => (string) ($raw['label'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function call(Request $request): array
    {
        try {
            $response = $this->connector->send($request);
        } catch (FatalRequestException $e) {
            throw new DaemonTransportException('Workspace daemon unreachable: '.$e->getMessage(), null, null, $e);
        } catch (RequestException $e) {
            $body = $e->getResponse()->json();
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];

            throw new DaemonTransportException(
                (string) ($error['message'] ?? 'Workspace daemon request failed'),
                $e->getResponse()->status(),
                isset($error['code']) ? (string) $error['code'] : null,
                $e,
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
