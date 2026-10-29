<?php

// Added to Laravel's own mailers (smtp, log, ...); choose one with MAIL_MAILER.
return [
    'mailers' => [
        // Microsoft 365 through Microsoft Graph: see docs/DEPLOYMENT.md (Email).
        'graph' => [
            'transport' => 'graph',
            'tenant_id' => env('MAIL_GRAPH_TENANT_ID'),
            'client_id' => env('MAIL_GRAPH_CLIENT_ID'),
            'client_secret' => env('MAIL_GRAPH_CLIENT_SECRET'),
        ],
    ],
];
