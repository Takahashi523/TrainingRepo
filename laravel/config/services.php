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

    /*
    |--------------------------------------------------------------------------
    | マッチングエンジン（Python / FastAPI）
    |--------------------------------------------------------------------------
    |
    | Laravel → Python の内部 REST 通信先。スコアリングロジック設計書（PR #12）
    | §1.2 / §4 に準拠する。URL を差し替えるだけで、実エンジン・ローカル代替
    | エンジンを切り替えられる（コード変更不要）。timeout はクライアント側の
    | 上限（#12 §1.2：クライアント 10 秒）。
    |
    */

    'matching_engine' => [
        'url' => env('MATCHING_ENGINE_URL', 'http://python:8001'),
        'timeout' => (int) env('MATCHING_ENGINE_TIMEOUT', 10),
    ],

];
