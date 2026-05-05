<?php

// Closed-deployment app — Argos does not send mail. The 'log' default is here
// only so framework features that depend on the Mail manager (notifications,
// queued jobs that touch Mailables) don't crash if ever invoked.

return [

    'default' => 'log',

    'mailers' => [

        'log' => [
            'transport' => 'log',
            'channel' => null,
        ],

        'array' => [
            'transport' => 'array',
        ],

    ],

    'from' => [
        'address' => 'noreply@argos.local',
        'name' => 'Argos',
    ],

];
