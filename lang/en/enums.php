<?php

declare(strict_types=1);

return [
    'worker_source' => [
        'standard' => 'Standard',
        'byoi' => 'Bring Your Own Image (BYOI)',
        'devcontainer' => 'Devcontainer',
    ],

    'worker_image_entity_status' => [
        'active' => 'Active',
        'deprecated' => 'Deprecated',
        'disabled' => 'Disabled',
    ],

    'worker_image_build_status' => [
        'queued' => 'Queued',
        'building' => 'Building',
        'ready' => 'Ready',
        'failed' => 'Failed',
    ],

    'agent_credential_status' => [
        'active' => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],

    'provider_credential_status' => [
        'active' => 'Active',
        'expired' => 'Expired',
        'revoked' => 'Revoked',
    ],

    'demo_status' => [
        'building' => 'Building',
        'live' => 'Live',
        'failed' => 'Failed',
        'stopped' => 'Stopped',
    ],
    'task_provider_mode' => [
        'webhook' => 'Webhook (push)',
        'poll' => 'Polling',
        'disabled' => 'Disabled',
    ],

    'task_provider_sync_status' => [
        'pending' => 'Pending',
        'active' => 'Active',
        'error' => 'Error',
    ],

    'workspace_status' => [
        'provisioning' => 'Provisioning',
        'starting' => 'Starting',
        'running' => 'Running',
        'stopping' => 'Stopping',
        'stopped' => 'Stopped',
        'failed' => 'Failed',
    ],

    'workspace_role' => [
        'owner' => 'Owner',
        'editor' => 'Editor',
        'viewer' => 'Viewer',
    ],

    'gate_run_status' => [
        'running' => 'Running',
        'passed' => 'Passed',
        'failed' => 'Failed',
        'error' => 'Error',
    ],
    'auto_thread_state' => [
        'queued' => 'Queued',
        'planning' => 'Planning',
        'awaiting_plan_approval' => 'Plan waits for approval',
        'implementing' => 'Implementing',
        'gating' => 'Checking gates',
        'fixing' => 'Fixing gate failures',
        'shipping' => 'Shipping',
        'done' => 'Done',
        'paused' => 'Paused',
        'failed' => 'Failed',
        'budget_exceeded' => 'Budget exceeded',
    ],
    'auto_plan_mode' => [
        'off' => 'No plan — implement right away',
        'auto' => 'Plan, then continue automatically',
        'approve' => 'Plan, then wait for approval',
    ],
    'auto_ship_mode' => [
        'pr' => 'Pull request',
        'draft_pr' => 'Draft pull request',
        'none' => 'Commit and push only',
    ],
    'workspace_origin' => [
        'manual' => 'Manual',
        'api' => 'API',
        'mcp' => 'MCP',
        'issue' => 'Issue',
    ],
    'thread_status' => [
        'closed' => 'Closed',
        'starting' => 'Starting',
        'idle' => 'Idle',
        'running' => 'Working',
        'waiting_input' => 'Waiting for input',
        'error' => 'Error',
    ],

    'permission_mode' => [
        'default' => 'Ask before changes',
        'acceptEdits' => 'Accept edits',
        'plan' => 'Plan mode',
        'bypassPermissions' => 'Bypass permissions',
    ],

    'approval_kind' => [
        'tool' => 'Tool call',
        'plan' => 'Plan',
        'question' => 'Question',
    ],

    'approval_status' => [
        'pending' => 'Pending',
        'allowed' => 'Allowed',
        'denied' => 'Denied',
        'answered' => 'Answered',
        'cancelled' => 'Cancelled',
    ],
];
