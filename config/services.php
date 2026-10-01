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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
    ],

    /*
    | Kurum geneli deneme PDF'i okuma (1 Ekim 2026, README SS14).
    | provider bossa: ANTHROPIC_API_KEY varsa Claude, yoksa OpenAI.
    */
    'exam_ai' => [
        'provider' => env('EXAM_AI_PROVIDER'),
        'anthropic_model' => env('EXAM_AI_ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'openai_model' => env('EXAM_AI_OPENAI_MODEL', 'gpt-4o'),
        'effort' => env('EXAM_AI_EFFORT', 'low'),
    ],

];
