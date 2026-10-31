<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | Adiva's bot (Tahap 4). "tiruan" changes the database locally and sends nothing,
    | which demo mode and the tests always use; "langsung" talks to n8n and WAHA;
    | "lokal" has the panel do what it has taken over from n8n (order commands, invoice,
    | shipping) and leaves the rest with n8n. See docs/kontrak-n8n.md.
    */

    'bot' => [
        'driver' => env('BOT_DRIVER', 'tiruan'),
    ],

    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL'),
        'webhook_secret' => env('N8N_WEBHOOK_SECRET'),
        'timeout' => (int) env('N8N_TIMEOUT', 15),
        // Uji Adiva waits for the AI agent
        'timeout_jawab' => (int) env('N8N_TIMEOUT_JAWAB', 180),
        'timeout_uji' => (int) env('N8N_TIMEOUT_UJI', 60),
        // Otomasi: "Buka n8n" opens the editor here
        'editor_url' => env('N8N_EDITOR_URL'),
        // n8n:sinkron reads workflows and runs from the n8n public API
        'api_url' => env('N8N_API_URL'),
        'api_key' => env('N8N_API_KEY'),
    ],

    /*
    | Accurate Online, database "Bana Food": Toko Sawojajar's goods, read only
    | (accurate:sinkron). Either OAuth ("Sambungkan Accurate" with the app's Client ID and
    | Secret; the redirect must equal the app's OAuth Callback URL) or an API Token.
    */

    'accurate' => [
        'client_id' => env('ACCURATE_CLIENT_ID'),
        'client_secret' => env('ACCURATE_CLIENT_SECRET'),
        'redirect' => env('ACCURATE_REDIRECT_URI') ?: rtrim((string) env('APP_URL'), '/').'/accurate/kembali',
        'token' => env('ACCURATE_API_TOKEN'),
        'signature_secret' => env('ACCURATE_SIGNATURE_SECRET'),
        'db_id' => env('ACCURATE_DB_ID') ?: '2743429',
        'timeout' => (int) env('ACCURATE_TIMEOUT', 30),
        'cron_key' => env('ACCURATE_CRON_KEY'),
    ],

    'waha' => [
        'url' => env('WAHA_URL'),
        'key' => env('WAHA_API_KEY'),
        'timeout' => (int) env('WAHA_TIMEOUT', 15),
        // WAHA signs each webhook it sends (HMAC, header X-Webhook-Hmac) with this key
        'webhook_secret' => env('WAHA_WEBHOOK_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
