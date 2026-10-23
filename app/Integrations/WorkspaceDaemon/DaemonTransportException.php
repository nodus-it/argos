<?php

declare(strict_types=1);

namespace App\Integrations\WorkspaceDaemon;

use RuntimeException;
use Throwable;

/**
 * A daemon call failed. `$status` is null when the daemon was unreachable
 * (container down, network), otherwise the HTTP status; `$errorCode` is the
 * daemon's `error.code` when it sent one.
 */
class DaemonTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public function isUnreachable(): bool
    {
        return $this->status === null;
    }
}
