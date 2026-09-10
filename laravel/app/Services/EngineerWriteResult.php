<?php

namespace App\Services;

use App\Models\Engineer;

/**
 * {@see EngineerService} の登録・更新結果を表す不変 DTO。
 *
 * 案件側の {@see ProjectService} は Model 単体を返すが、人材側は「保存は成功したが AI 要約生成だけ失敗した」
 * という半失敗状態を Controller へ伝える必要がある（submit 直後に失敗トーストを出すため）。DB 状態からは
 * 失敗と未生成を区別できないため（CSV インポートでも未生成の appeal_note あり行が生じる）、この実行時
 * シグナルで区別する。
 */
final class EngineerWriteResult
{
    public function __construct(
        public readonly Engineer $engineer,
        /** AI 要約生成が上流障害で失敗したら true（空出力＝未生成は false）。 */
        public readonly bool $aiSummaryFailed = false,
    ) {}
}
