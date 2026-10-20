<?php

declare(strict_types=1);

return [
    'errors' => [
        'capacity' => 'Already :max workspaces are running. Stop one before starting another.',
        'init_failed' => 'Setting up the repository failed (:code): :message',
        'agent_unavailable' => 'The agent of this thread is not available.',
        'agent_usage_limited' => 'The subscription of :agent has hit its usage limit until :time.',
        'agent_busy' => ':agent already runs :max parallel session(s). Close another thread or pick a different agent.',
        'collaboration_disabled' => 'Adding members to workspaces is not enabled on this installation.',
        'last_owner' => 'A workspace needs at least one owner.',
    ],
];
