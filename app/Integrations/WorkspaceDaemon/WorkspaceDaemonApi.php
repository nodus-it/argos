<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon;

use App\Integrations\WorkspaceDaemon\Requests\CreateCheckpoint;
use App\Integrations\WorkspaceDaemon\Requests\GetCheckpointDiff;
use App\Integrations\WorkspaceDaemon\Requests\GetGateFixPrompt;
use App\Integrations\WorkspaceDaemon\Requests\GetHealth;
use App\Integrations\WorkspaceDaemon\Requests\GitCommit;
use App\Integrations\WorkspaceDaemon\Requests\GitDiff;
use App\Integrations\WorkspaceDaemon\Requests\GitFetch;
use App\Integrations\WorkspaceDaemon\Requests\GitPush;
use App\Integrations\WorkspaceDaemon\Requests\GitStatus;
use App\Integrations\WorkspaceDaemon\Requests\InitWorkspace;
use App\Integrations\WorkspaceDaemon\Requests\InterruptThread;
use App\Integrations\WorkspaceDaemon\Requests\ListFiles;
use App\Integrations\WorkspaceDaemon\Requests\ResolveApproval;
use App\Integrations\WorkspaceDaemon\Requests\RestoreCheckpoint;
use App\Integrations\WorkspaceDaemon\Requests\RunGates;
use App\Integrations\WorkspaceDaemon\Requests\RunOneshot;
use App\Integrations\WorkspaceDaemon\Requests\SendMessage;
use App\Integrations\WorkspaceDaemon\Requests\SetModel;
use App\Integrations\WorkspaceDaemon\Requests\SetPermissionMode;
use App\Integrations\WorkspaceDaemon\Requests\StartThread;
use App\Integrations\WorkspaceDaemon\Requests\StopThread;
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
    public function startThread(string $threadId, array $payload): array
    {
        return $this->call(new StartThread($threadId, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(string $threadId, array $payload): array
    {
        return $this->call(new SendMessage($threadId, $payload));
    }

    public function interrupt(string $threadId): void
    {
        $this->call(new InterruptThread($threadId));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function resolveApproval(string $threadId, string $approvalId, array $payload): void
    {
        $this->call(new ResolveApproval($threadId, $approvalId, $payload));
    }

    public function setPermissionMode(string $threadId, string $mode): void
    {
        $this->call(new SetPermissionMode($threadId, ['mode' => $mode]));
    }

    public function setModel(string $threadId, string $model): void
    {
        $this->call(new SetModel($threadId, ['model' => $model]));
    }

    public function stopThread(string $threadId): void
    {
        $this->call(new StopThread($threadId));
    }

    public function gitStatus(): array
    {
        /** @var array{branch: string|null, upstream: string|null, ahead: int, behind: int, dirty: bool, files: list<array{path: string, status: string}>} */
        return $this->call(new GitStatus) + ['branch' => null, 'upstream' => null, 'ahead' => 0, 'behind' => 0, 'dirty' => false, 'files' => []];
    }

    public function gitDiff(string $base): array
    {
        $result = $this->call(new GitDiff($base));

        return ['diff' => (string) ($result['diff'] ?? ''), 'truncated' => (bool) ($result['truncated'] ?? false)];
    }

    public function gitFetch(): void
    {
        $this->call(new GitFetch);
    }

    public function gitCommit(string $subject, string $body, array $author): ?array
    {
        try {
            $result = $this->call(new GitCommit(['subject' => $subject, 'body' => $body, 'author' => $author]));
        } catch (DaemonTransportException $e) {
            if ($e->errorCode === 'no_changes') {
                return null;
            }
            throw $e;
        }

        return ['sha' => (string) ($result['sha'] ?? '')];
    }

    public function gitPush(string $branch): void
    {
        $this->call(new GitPush(['branch' => $branch]));
    }

    public function oneshot(array $payload): array
    {
        $result = $this->call(new RunOneshot($payload));

        return ['text' => (string) ($result['text'] ?? ''), 'structured' => $result['structured'] ?? null, 'cost_usd' => $result['cost_usd'] ?? 0];
    }

    public function files(string $query, int $limit = 20): array
    {
        $files = $this->call(new ListFiles($query, $limit))['files'] ?? [];

        return array_values(array_filter(is_array($files) ? $files : [], 'is_string'));
    }

    public function runGates(string $runId): void
    {
        $this->call(new RunGates(['run_id' => $runId]));
    }

    public function gateFixPrompt(string $gate, string $runId): string
    {
        return (string) ($this->call(new GetGateFixPrompt($gate, $runId))['prompt'] ?? '');
    }

    public function createCheckpoint(string $threadId, string $label): array
    {
        return self::checkpoint($this->call(new CreateCheckpoint(['thread_id' => $threadId, 'label' => $label])));
    }

    public function checkpointDiff(string $ref): array
    {
        $result = $this->call(new GetCheckpointDiff($ref));

        return ['diff' => (string) ($result['diff'] ?? ''), 'truncated' => (bool) ($result['truncated'] ?? false), 'head' => is_string($result['head'] ?? null) ? $result['head'] : null];
    }

    public function restoreCheckpoint(string $ref): array
    {
        $result = $this->call(new RestoreCheckpoint(['ref' => $ref]));
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
