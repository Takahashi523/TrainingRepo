import { Button } from '@/Components/ui/button';
import { ImportError, summarizeImportErrors } from '@/types/csv';
import { AlertOctagon } from 'lucide-react';

interface Props {
    /** 保持中のエラー結果（モーダルを閉じても破棄しない）。 */
    errors: ImportError[];
    /** 「詳細を表示」でモーダルを再オープンする。 */
    onReopen: () => void;
}

/**
 * インポート結果バナー（WF_11 v1.2 / 詳細設計）。
 *
 * 結果モーダルを閉じた後もインポート欄に残す1行の要約。エラー時のみ表示し、
 * 「詳細を表示」で結果モーダルを再オープンできる（結果 state は保持されている前提）。
 * 成功時はバナーを残さずトーストで通知するため、本バナーはエラー専用。
 * インポート欄自体を伸ばさないよう1行に収める。
 */
export default function ImportResultBanner({ errors, onReopen }: Props) {
    const { errorRowCount, messageCount } = summarizeImportErrors(errors);

    return (
        <div className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3.5 py-2 text-xs font-semibold text-destructive">
            <AlertOctagon className="h-4 w-4 shrink-0" />
            <span className="min-w-0 truncate">
                前回のインポートは
                {errorRowCount > 0 ? `${errorRowCount}行でエラー` : 'エラー'}
                （メッセージ {messageCount}件）— 未反映です。
            </span>
            <Button
                type="button"
                variant="outline"
                onClick={onReopen}
                className="ml-auto h-7 shrink-0 border-destructive/40 px-2.5 text-xs text-destructive hover:bg-destructive/10 hover:text-destructive"
            >
                詳細を表示
            </Button>
        </div>
    );
}
