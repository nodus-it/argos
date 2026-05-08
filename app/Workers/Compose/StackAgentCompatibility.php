<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use App\Models\WorkerStack;
use App\Workers\Agents\AgentSpec;

/**
 * Pre-build sanity check: does the stack provide every capability the
 * agent needs? Catches invalid combinations (e.g. a python-only stack
 * paired with claude-code, which needs node) before we spend three
 * minutes building an image that would fail at `npm install`.
 */
final class StackAgentCompatibility
{
    /**
     * @return list<string> capabilities required by the agent but missing on the stack; empty list = compatible
     */
    public static function missingCapabilities(WorkerStack $stack, AgentSpec $agent): array
    {
        $required = $agent->requiresStackCapabilities;
        $available = $stack->capabilities ?? [];

        return array_values(array_diff($required, $available));
    }

    public static function isCompatible(WorkerStack $stack, AgentSpec $agent): bool
    {
        return self::missingCapabilities($stack, $agent) === [];
    }
}
