<?php

declare(strict_types=1);

namespace App\Services\Workspace;

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
}
