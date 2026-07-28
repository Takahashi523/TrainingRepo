<?php

namespace App\Services\Matching;

/**
 * マッチングエンジン（Python）が返す1案件分の判定結果（5点セット＋案件ID）。
 *
 * スコアリングロジック設計書（PR #12）§3.2「出力構造（5点セット）」・§4.2 レスポンスに対応する。
 * マッチング結果自体は DB 保存しない（QA #45）ため Model ではなく不変の DTO として表現し、
 * Controller / Resource へは案件情報（DB 取得）と突合したうえで渡す。
 */
final class MatchResult
{
    public function __construct(
        public readonly int $projectId,
        public readonly int $matchScore,
        public readonly string $matchRank,
        public readonly ?string $aiScoreReason,
        public readonly ?string $aiComment,
        public readonly ?string $aiMissing,
    ) {}

    /**
     * エンジンレスポンスの matches[] 1要素（連想配列）から生成する。
     *
     * 必須キー（project_id / match_score / match_rank）が欠落・空・非数値の不正な 200 応答は、
     * 黙って (int) キャストで 0 に潰して結果を静かに脱落させず（Silent Rejection）、上流障害として
     * 例外にする（Controller 側で engine_error 表示＋flash に集約される）。
     *
     * @param  array<string, mixed>  $match
     *
     * @throws MatchingEngineException 必須キー欠落・型不正
     */
    public static function fromArray(array $match): self
    {
        foreach (['project_id', 'match_score'] as $key) {
            if (! isset($match[$key]) || ! is_numeric($match[$key])) {
                throw MatchingEngineException::upstream("matching engine response missing/invalid '{$key}'");
            }
        }

        if (! isset($match['match_rank']) || $match['match_rank'] === '') {
            throw MatchingEngineException::upstream("matching engine response missing 'match_rank'");
        }

        return new self(
            projectId: (int) $match['project_id'],
            matchScore: (int) $match['match_score'],
            matchRank: (string) $match['match_rank'],
            aiScoreReason: $match['ai_score_reason'] ?? null,
            aiComment: $match['ai_comment'] ?? null,
            aiMissing: $match['ai_missing'] ?? null,
        );
    }
}
