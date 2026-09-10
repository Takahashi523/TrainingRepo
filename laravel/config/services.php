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
        // 接続確立のタイムアウト（秒）。timeout は接続後の総応答時間しか縛らないため、
        // ホスト到達不能・DNS 詰まり時に接続で長時間ブロックしないよう別途上限を設ける。
        'connect_timeout' => (int) env('MATCHING_ENGINE_CONNECT_TIMEOUT', 5),
    ],

    // 人材プロフィール要約 API（PR #12 E2）。Python は既定でマッチングと同一サービスだが、
    // 関心分離のため別ブロックで持つ（接続先は個別に上書き可能）。E2 は AI 呼出を含むため
    // timeout はマッチング（10s）より長い 30s を既定とする（#12 §4.4）。
    'ai_summary' => [
        'url' => env('AI_SUMMARY_URL', env('MATCHING_ENGINE_URL', 'http://python:8001')),
        'timeout' => (int) env('AI_SUMMARY_TIMEOUT', 30),
        'connect_timeout' => (int) env('AI_SUMMARY_CONNECT_TIMEOUT', 5),

        // CSVインポート由来の一括生成トリガー（issue #61 課題4）の経過時間予算。
        // PHP の max_execution_time（既定30秒・docker/php・nginx 設定に上書きなし）に対し、
        // CSV読込・検証・バッチ書き込み自体で最大十数秒を使う想定（08_CSV入出力_APIエンドポイント一覧.md
        // O-13）のため、30秒からフレームワークのオーバーヘッド・レスポンス生成・安全マージンとして
        // 10秒を差し引いた20秒を既定値とする。EngineerService::triggerAiSummaryForCsvImport() が
        // インポート開始時刻からの経過時間としてこの値と比較し、超過後は新規のAI呼び出しを行わない。
        'csv_trigger_budget_seconds' => (float) env('AI_SUMMARY_CSV_TRIGGER_BUDGET_SECONDS', 20),
    ],

];
