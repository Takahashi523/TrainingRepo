<?php

namespace App\Services\Matching;

/**
 * マッチングエンジン（Python）呼び出しの抽象。
 *
 * 実装は HTTP（{@see HttpMatchingEngineClient}）だが、テストでは Http::fake で
 * 差し込むか、この IF を差し替える。呼び出し側（MatchingController）は具象に
 * 依存しない（DIP）。将来の通信手段変更もこの IF の実装差し替えで吸収する。
 */
interface MatchingEngineClient
{
    /**
     * 指定人材に対するマッチング判定を実行し、スコア降順・最大5件の結果を返す。
     *
     * スコアリングロジック設計書（PR #12）§4.2 E1 `POST /api/v1/matching/calculate` に対応。
     *
     * @param  int  $engineerId  対象人材ID
     * @param  array<int, int>|null  $projectIds  指定時はこの案件群のみで判定。未指定は status='open' 全件
     * @return array<int, MatchResult> スコア降順・最大5件
     *
     * @throws MatchingEngineException 404（NotFound）/ 422（NoCandidate）/ 上流障害（Upstream）
     */
    public function calculate(int $engineerId, ?array $projectIds = null): array;
}
