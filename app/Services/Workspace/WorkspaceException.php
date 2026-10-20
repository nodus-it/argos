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

    /** @param  array<string, mixed>  $replace */
    public static function translated(string $key, array $replace = []): static
    {
        return new static(__($key, $replace));
    }

    /**
     * Map a failed daemon call to a user-facing failure: a known daemon
     * `error.code` to its translation key, anything else to "unreachable".
     *
     * @param  array<string, string>  $codeMap  daemon error code => translation key
     */
    public static function fromDaemon(DaemonTransportException $e, array $codeMap = []): static
    {
        $key = ($e->errorCode !== null ? ($codeMap[$e->errorCode] ?? null) : null) ?? 'workspaces.errors.daemon_unreachable';

        return new static(__($key, ['message' => $e->getMessage()]), 0, $e);
    }
}
