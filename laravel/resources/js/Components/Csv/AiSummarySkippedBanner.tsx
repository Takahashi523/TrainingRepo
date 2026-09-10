import { AiSummarySkipped } from '@/types/csv';
import { AlertTriangle } from 'lucide-react';

interface Props {
    /** 生成トリガーの結果（実行件数・スキップ件数）。 */
    result: AiSummarySkipped;
}

/**
 * CSVインポート経由のAI要約一括生成が、経過時間予算の超過で一部スキップされたときの常設バナー
 * （issue #61 課題4）。
 *
 * 元々はトースト（flash.error）のみで通知していたが、同一レスポンスで発火する成功トースト
 * （CsvImportSection::onSuccess）と衝突し、トースト実装の TOAST_LIMIT=1 により黙って上書き消去
 * されてしまう不具合が手動確認で見つかった。成功バナー（ImportSuccessBanner）と同じ「常設バナー」
 * にすることで、トーストの競合に関係なく確実に表示・確認できるようにする。
 */
export default function AiSummarySkippedBanner({ result }: Props) {
    return (
        <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3.5 py-2 text-xs font-semibold text-amber-800">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
            <span className="min-w-0">
                AI要約の生成は{result.triggered}件実行しました。処理時間の上限に達したため、残り{result.skipped}
                件はスキップしました。対象の人材詳細画面から個別に再生成してください。
            </span>
        </div>
    );
}
