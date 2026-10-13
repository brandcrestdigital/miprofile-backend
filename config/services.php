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

    'resend'   => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses'      => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack'    => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'razorpay' => [
        'key'    => env('RAZORPAY_KEY'),
        'secret' => env('RAZORPAY_SECRET'),
    ],

    'google'    => [
        // OAuth (Socialite — Sign in with Gmail)
        'client_id'             => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret'         => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect'              => env('GOOGLE_OAUTH_REDIRECT'),

        // Calendar API (Service Account)
        'calendar_id'           => env('GOOGLE_CALENDAR_ID', 'primary'),
        'calendar_impersonate'  => env('GOOGLE_CALENDAR_IMPERSONATE'),
    ],

    'cashfree' => [
        'environment' => env('CASHFREE_ENV', 'sandbox'),
        'client_id' => env('CASHFREE_CLIENT_ID'),
        'client_secret' => env('CASHFREE_CLIENT_SECRET'),
        'api_version' => env('CASHFREE_API_VERSION', '2023-08-01'),
    ],

];
