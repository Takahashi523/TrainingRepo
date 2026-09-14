<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 楽観ロック（version列）による更新競合を表す例外（issue #45）。
 *
 * 編集フォームが読み込んだ version と、DB上の現在の version が一致しない場合に投げる。
 * 他のユーザーが先に同じレコードを更新した（ロストアップデートになり得る）ことを示す。
 * 呼び出し側（各 Controller の update()）はこれを捕捉し、最新データの再読み込みを促す
 * フラッシュメッセージ付きで編集画面へ差し戻す。
 */
class StaleUpdateException extends RuntimeException
{
    public static function forVersionMismatch(): self
    {
        return new self('resource version mismatch');
    }
}