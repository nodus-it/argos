<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Persisted entries of a task's history. Streaming deltas are broadcast
 * only and never stored, so they have no case here.
 */
enum TaskEventType: string
{
    case UserMessage = 'user_message';
    case AssistantMessage = 'assistant_message';
    case Thinking = 'thinking';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case ApprovalRequested = 'approval_requested';
    case ApprovalResolved = 'approval_resolved';
    case TurnCompleted = 'turn_completed';
    case StatusChanged = 'status_changed';
    case Error = 'error';
    case UsageLimit = 'usage_limit';
    case Notice = 'notice';
}
