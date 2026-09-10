<?php

namespace App\Services\Ai;

use App\Models\Engineer;
use App\Services\EngineerService;
use App\Services\Matching\MatchingEngineClient;

/**
 * 人材プロフィール要約（Python）呼び出しの抽象。
 *
 * 実装は HTTP（{@see HttpAiSummaryClient}）だが、テストでは Http::fake で差し込むか、この IF を
 * 差し替える。呼び出し側（{@see EngineerService}）は具象に依存しない（DIP）。
 * マッチングの {@see MatchingEngineClient} と同じ方針。
 */
interface AiSummaryClient
{
    /**
     * 指定人材のプロフィール要約を生成する（PR #12 E2 `POST /api/v1/ai/profile-summary`）。
     *
     * @return AiSummaryResult|null 生成結果。Python が空出力を返した場合（要約対象なし）は null
     *
     * @throws AiSummaryException 接続不可・タイムアウト・4xx/5xx 等の上流障害
     */
    public function generate(Engineer $engineer): ?AiSummaryResult;
}
