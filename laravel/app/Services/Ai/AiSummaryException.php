<?php

namespace App\Services\Ai;

use App\Services\EngineerService;
use App\Services\Matching\MatchingEngineException;
use RuntimeException;

/**
 * 人材プロフィール要約 API（PR #12 E2）呼び出し時の上流障害を表す例外。
 *
 * 接続不可・クライアントタイムアウト・4xx/5xx（504 リトライ後失敗を含む）をまとめて「上流障害」として
 * 扱う。AI 要約は登録・更新の付加情報であり本体の成否に影響させないため（#21-🔴4）、呼び出し側
 * （{@see EngineerService}）はこの例外を捕捉して Log::warning に留め、人材の保存自体は
 * 成功として扱う。マッチングの {@see MatchingEngineException} に倣うが、要約は
 * NotFound / NoCandidate の分岐が不要なため単一種別とする。
 */
class AiSummaryException extends RuntimeException
{
    public static function upstream(string $message = 'ai summary engine error', ?\Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
