import { ImportSummary } from '@/types/csv';
import { CheckCircle2 } from 'lucide-react';

interface Props {
    /** 成功サマリ（新規/更新件数）。 */
    summary: ImportSummary;
}

/**
 * インポート成功バナー（WF_11 v1.2 の成功フィードバック拡張）。
 *
 * 一括書き込みは高影響のため、成功時もトーストで消えるだけでなく結果を欄に常設する。
 * エラーバナー（ImportResultBanner）と対になる成功版で、インポート欄を伸ばさないよう1行に収める。
 * 配色は成功トースト（ui/toast の success バリアント）と同じ緑系に統一する。
 * ライフサイクルはエラーバナーと同じ（新ファイル選択・ファイル取消でクリア）。
 */
export default function ImportSuccessBanner({ summary }: Props) {
    return (
        <div className="flex items-center gap-2 rounded-md border border-green-200 bg-green-50 px-3.5 py-2 text-xs font-semibold text-green-900">
            <CheckCircle2 className="h-4 w-4 shrink-0 text-green-600" />
            <span className="min-w-0 truncate">
                インポートが完了しました：新規追加 {summary.created}件 / 更新 {summary.updated}件
            </span>
        </div>
    );
}
