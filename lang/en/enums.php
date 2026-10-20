<?php

declare(strict_types=1);

return [
    'workflow_status' => [
        'draft' => 'Draft',
        'concept_running' => 'Concept running',
        'concept_review' => 'Concept ready',
        'implement_running' => 'Implementation running',
        'implement_paused' => 'Paused (turn limit)',
        'implement_completed' => 'Implementation completed',
        'in_review' => 'In Review',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'aborted' => 'Aborted',
    ],

    'phase_status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'completed' => 'Completed',
        'paused' => 'Paused',
        'failed' => 'Failed',
        'quality_gate_failed' => 'Quality Gate Failed',
        'no_changes' => 'No Changes',
        'lock_blocked' => 'Lock Blocked',
        'rate_limited' => 'Rate Limited',
    ],

    'phases' => [
        'concept' => 'Concept',
        'implement' => 'Implement',
        'diff' => 'Diff',
        'push' => 'Push',
        'respond' => 'Respond',
        'commit_message' => 'Commit Message',
    ],

    'phase_runs' => [
        'title' => 'Phase Runs',
        'columns' => [
            'phase' => 'Phase',
            'iteration' => '#',
            'status' => 'Status',
            'started' => 'Started',
            'finished' => 'Finished',
            'duration' => 'Duration',
            'input' => 'Input',
            'output' => 'Output',
            'cost' => 'Cost',
            'model' => 'Model',
        ],
    ],

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
    'demo_access_mode' => [
        'inherit' => 'Default',
        'session' => 'Argos login',
        'basic' => 'Password',
        'public' => 'Public',
        'workspace' => 'Workspace members',
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
