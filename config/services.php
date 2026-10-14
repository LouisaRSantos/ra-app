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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        'endpoint' => env('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
    ],

    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'qwen/qwen3.8-27b'),
        'endpoint' => env('GROQ_API_ENDPOINT', 'https://api.groq.com/openai/v1/chat/completions'),
    ],

    'claude_routine' => [
        'id' => env('CLAUDE_ROUTINE_ID'),
        'token' => env('CLAUDE_ROUTINE_TOKEN'),
        'beta' => env('CLAUDE_ROUTINE_BETA', 'experimental-cc-routine-2026-04-01'),
        'endpoint' => env('CLAUDE_ROUTINE_ENDPOINT', 'https://api.anthropic.com/v1/claude_code/routines'),
    ],

];
