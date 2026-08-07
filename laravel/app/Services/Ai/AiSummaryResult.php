<?php

namespace App\Services\Ai;

/**
 * 人材プロフィール要約 API（PR #12 E2 `POST /api/v1/ai/profile-summary`）の成功レスポンスを表す不変 DTO。
 *
 * Python 側は要約テキストと生成時刻を「返却するのみ」で、DB 保存は Laravel（呼び出し側）の責務
 * （#12 の「Python が登録」は誤りとして Python チームと認識合わせ済み）。生成時刻は Python が返す
 * ISO8601 文字列をそのまま `ai_summary_generated_at` に保存する。
 */
final class AiSummaryResult
{
    public function __construct(
        public readonly string $summary,
        public readonly string $generatedAt,
    ) {}

    /**
     * E2 の 200 レスポンス（連想配列）から生成する。
     *
     * `ai_summary` が空／未設定のケースは呼び出し側（{@see HttpAiSummaryClient::generate()}）で
     * null 判定して本メソッドに渡さないため、ここでは非空前提で組み立てる。
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            summary: (string) $body['ai_summary'],
            generatedAt: (string) $body['ai_summary_generated_at'],
        );
    }
}
