<?php

namespace App\Services\Ai;

use App\Models\Engineer;
use App\Services\Matching\HttpMatchingEngineClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 人材プロフィール要約 API（Python / FastAPI）への実 HTTP クライアント。
 *
 * PR #12 の AIプロンプト設計書 §4.3 E2 `POST /api/v1/ai/profile-summary` に準拠する。データ連携は
 * Python 側が `engineer_id` から `appeal_note` を取得して要約するため、本クライアントは ID を送るのみ。
 * 接続先・タイムアウトは config/services.php（env 外部化）で切り替える。E2 は AI 呼出を含むため
 * タイムアウトはマッチング（10s）より長い 30s を既定とする（#12 §4.4）。マッチングの
 * {@see HttpMatchingEngineClient} と同じ構成。
 */
class HttpAiSummaryClient implements AiSummaryClient
{
    public function generate(Engineer $engineer): ?AiSummaryResult
    {
        $baseUrl = rtrim((string) config('services.ai_summary.url'), '/');
        $timeout = (int) config('services.ai_summary.timeout', 30);
        $connectTimeout = (int) config('services.ai_summary.connect_timeout', 5);

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/v1/ai/profile-summary', ['engineer_id' => $engineer->id]);
        } catch (ConnectionException $e) {
            // 接続不可・クライアントタイムアウト（#12 §4.4）は上流障害として扱う。
            throw AiSummaryException::upstream('ai summary engine connection failed', $e);
        }

        if ($response->failed()) {
            // 504（リトライ後失敗）を含む 4xx/5xx。要約は付加情報のため上流障害として呼び出し側で握る。
            throw AiSummaryException::upstream("ai summary engine returned HTTP {$response->status()}");
        }

        // 空出力（要約対象なし）は #12 §4.3 のとおり ai_summary を更新しない。ここでは null を返し、
        // 呼び出し側で「失敗ではない未生成」として扱う（失敗トーストも出さない）。
        $summary = trim((string) $response->json('ai_summary', ''));
        if ($summary === '') {
            return null;
        }

        return AiSummaryResult::fromArray((array) $response->json());
    }
}
