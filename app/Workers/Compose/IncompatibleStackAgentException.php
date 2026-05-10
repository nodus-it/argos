<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use App\Models\WorkerStack;
use App\Workers\Agents\AgentSpec;
use RuntimeException;

class IncompatibleStackAgentException extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     */
    public static function forMissing(WorkerStack $stack, AgentSpec $agent, array $missing): self
    {
        return new self(sprintf(
            "Stack '%s' lacks capabilities required by agent '%s': %s",
            $stack->name,
            $agent->name->value,
            implode(', ', $missing),
        ));
    }
}
