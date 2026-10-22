<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.

return [

    'default' => 'local',

    'disks' => [

        'local' => [
            'driver' => 'local',
            // Uploads (avatars, task images) must outlive the container and be
            // readable by the queue too: compose points this at the shared /data volume.
            'root' => env('ARGOS_FILES_DIR', storage_path('app/private')),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
