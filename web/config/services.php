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

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI'),
        'bot_token' => env('DISCORD_BOT_TOKEN'),
    ],

    'flynn' => [
        'bot_token' => env('FLYNN_BOT_SERVICE_TOKEN'),
        'bot_service_abilities' => array_values(array_filter(array_map('trim', explode(',', env('FLYNN_BOT_SERVICE_ABILITIES', ''))))),
        'bot_hmac_secret' => env('FLYNN_BOT_HMAC_SECRET'),
        'bot_hmac_abilities' => array_values(array_filter(array_map('trim', explode(',', env('FLYNN_BOT_HMAC_ABILITIES', ''))))),
        'desktop_redirect_uri' => env('FLYNN_DESKTOP_REDIRECT_URI'),
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

];
