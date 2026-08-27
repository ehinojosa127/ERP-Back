<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'billing' => [
        'url' => env('BILLING_SERVICE_URL', 'http://localhost:5147'),
        'api_key' => env('BILLING_SERVICE_API_KEY', ''),
        'timeout' => (int) env('BILLING_SERVICE_TIMEOUT', 30),
        'external_system' => env('BILLING_EXTERNAL_SYSTEM', 'confecciones-erika-erp'),
    ],

    'automation' => [
        'api_key' => env('AUTOMATION_API_KEY', ''),
        'rate_limit_per_minute' => (int) env('AUTOMATION_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL', ''),
        'webhook_secret' => env('N8N_WEBHOOK_SECRET', ''),
    ],

];
