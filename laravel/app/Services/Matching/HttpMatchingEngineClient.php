<?php

namespace App\Services\Matching;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * マッチングエンジン（Python / FastAPI）への実 HTTP クライアント。
 *
 * スコアリングロジック設計書（PR #12）§4.2 E1 に準拠する。データ連携は §1.3（QA #48）の
 * とおり Python 側が RDS から直接取得するため、本クライアントは ID を送るのみで人材・案件の
 * 中身は送らない。接続先は config/services.php（env 外部化）で切り替える。
 */
class HttpMatchingEngineClient implements MatchingEngineClient
{
    public function calculate(int $engineerId, ?array $projectIds = null): array
    {
        $baseUrl = rtrim((string) config('services.matching_engine.url'), '/');
        $timeout = (int) config('services.matching_engine.timeout', 10);
        $connectTimeout = (int) config('services.matching_engine.connect_timeout', 5);

        $payload = ['engineer_id' => $engineerId];
        if ($projectIds !== null) {
            $payload['project_ids'] = array_values($projectIds);
        }

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/v1/matching/calculate', $payload);
        } catch (ConnectionException $e) {
            // 接続不可・クライアントタイムアウト（#12 §1.2）は上流障害として扱う。
            throw MatchingEngineException::upstream('matching engine connection failed', $e);
        }

        if ($response->failed()) {
            throw $this->mapErrorResponse($response->status(), (array) $response->json());
        }

        // matches[] をスコア降順・最大5件で受け取る前提（#12 §4.2）。念のため昇順混入に備えず
        // エンジンの並びを信頼するが、Controller 側でも降順維持の突合を行う。
        $matches = (array) $response->json('matches', []);

        return array_map(
            static fn (array $match) => MatchResult::fromArray($match),
            $matches
        );
    }

    /**
     * HTTP ステータス＋レスポンスボディの error_code を {@see MatchingEngineException} の種別へマップする（#12 §4.2）。
     *
     * 404 は「人材が存在しない／非掲出」の場合のみ notFound とする。エンジン自体が未デプロイで
     * パスが存在しない場合の裸の 404（`{"detail":"Not Found"}` 等・error_code なし）は
     * ENGINEER_NOT_FOUND ではないため上流障害として扱い、誤って「人材なし」404 を返さない。
     *
     * @param  array<string, mixed>  $body
     */
    private function mapErrorResponse(int $status, array $body): MatchingEngineException
    {
        $code = $body['error_code'] ?? null;

        if ($status === 404 && $code === 'ENGINEER_NOT_FOUND') {
            return MatchingEngineException::notFound('engineer not found or not listed');
        }

        if ($status === 422 && $code === 'NO_ACTIVE_PROJECT') {
            return MatchingEngineException::noCandidate('no active project for engineer');
        }

        return MatchingEngineException::upstream("matching engine returned HTTP {$status}");
    }
}
