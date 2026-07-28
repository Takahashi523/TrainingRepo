<?php

namespace App\Services\Matching;

use RuntimeException;

/**
 * マッチングエンジン（Python）呼び出し時の異常を種別付きで表現する。
 *
 * スコアリングロジック設計書（PR #12）§4.2 のエラーレスポンスを、Controller が
 * ユーザー向け挙動へ振り分けられるよう3種に分類する。HTTP ステータスを
 * そのまま扱わず種別で持つことで、通信手段（HTTP → gRPC 等）が変わっても
 * 呼び出し側の分岐を維持できる。
 */
class MatchingEngineException extends RuntimeException
{
    /** 対象人材が存在しない / ステータス非掲出（#12 §4.2：404 ENGINEER_NOT_FOUND）→ 404 応答 */
    public const KIND_NOT_FOUND = 'not_found';

    /** 候補案件が0件（#12 §4.2：422 NO_ACTIVE_PROJECT）→ 結果0件の空状態として扱う */
    public const KIND_NO_CANDIDATE = 'no_candidate';

    /** 400/500/504・接続失敗・タイムアウト等の上流障害 → ユーザーへエラー表示 */
    public const KIND_UPSTREAM = 'upstream';

    public function __construct(
        public readonly string $kind,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(string $message = 'engineer not found'): self
    {
        return new self(self::KIND_NOT_FOUND, $message);
    }

    public static function noCandidate(string $message = 'no active project'): self
    {
        return new self(self::KIND_NO_CANDIDATE, $message);
    }

    public static function upstream(string $message = 'matching engine error', ?\Throwable $previous = null): self
    {
        return new self(self::KIND_UPSTREAM, $message, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->kind === self::KIND_NOT_FOUND;
    }

    public function isNoCandidate(): bool
    {
        return $this->kind === self::KIND_NO_CANDIDATE;
    }
}
