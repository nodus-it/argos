<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use RuntimeException;
use Throwable;

/**
 * Expected, user-facing failure of a workspace operation. The message is
 * already translated and safe to show in a notification.
 */
class WorkspaceException extends RuntimeException
{
    /** Final so the static factory below can safely instantiate subclasses. */
    final public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /** Machine-readable reason (the last segment of the translation key), e.g. for the REST API. */
    public ?string $reason = null;

    /** @param  array<string, mixed>  $replace */
    public static function translated(string $key, array $replace = []): static
    {
        $e = new static(__($key, $replace));
        $e->reason = substr((string) strrchr('.'.$key, '.'), 1);

        return $e;
    }

    /**
     * Map a failed daemon call to a user-facing failure: a known daemon
     * `error.code` to its translation key; otherwise "did not respond" when
     * the daemon could not be reached, else "refused" with its message. The
     * daemon's code stays the reason (API clients see it).
     *
     * @param  array<string, string>  $codeMap  daemon error code => translation key
     */
    public static function fromDaemon(DaemonTransportException $e, array $codeMap = []): static
    {
        $key = ($e->errorCode !== null ? ($codeMap[$e->errorCode] ?? null) : null)
            ?? ($e->isUnreachable() ? 'workspaces.errors.daemon_unreachable' : 'workspaces.errors.daemon_failed');

        $exception = new static(__($key, ['message' => $e->getMessage()]), 0, $e);
        $exception->reason = $e->errorCode ?? ($e->isUnreachable() ? 'daemon_unreachable' : 'daemon_failed');

        return $exception;
    }

    /**
     * Run a daemon call, turning a transport failure into a WorkspaceException.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @param  array<string, string>  $codeMap  daemon error code => translation key
     * @return T
     */
    public static function wrapDaemon(callable $call, array $codeMap = []): mixed
    {
        try {
            return $call();
        } catch (DaemonTransportException $e) {
            throw static::fromDaemon($e, $codeMap);
        }
    }
}
