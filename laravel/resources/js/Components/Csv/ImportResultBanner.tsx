import { Button } from '@/Components/ui/button';
import { hasHeaderError, ImportError, summarizeImportErrors } from '@/types/csv';
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
 * 本バナーはエラー専用で、成功サマリは対になる ImportSuccessBanner が担う。
 * インポート欄自体を伸ばさないよう1行に収める。
 */
export default function ImportResultBanner({ errors, onReopen }: Props) {
    // バナーは要約のため件数（エラーのある行数）のみ。メッセージ内訳は「詳細を表示」のモーダルに委ねる。
    // ヘッダーエラーは「行」の概念に馴染まないため文言を出し分ける。
    const { errorRowCount } = summarizeImportErrors(errors);
    const headerError = hasHeaderError(errors);

    return (
        <div className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3.5 py-2 text-xs font-semibold text-destructive">
            <AlertOctagon className="h-4 w-4 shrink-0" />
            <span className="min-w-0 truncate">
                {headerError
                    ? 'インポートに失敗しました：ヘッダーにエラーがあり、1件もインポートされていません。'
                    : errorRowCount > 0
                      ? `インポートに失敗しました：エラーのある行が ${errorRowCount} 件あり、1件もインポートされていません。`
                      : 'インポートに失敗しました：エラーのため1件もインポートされていません。'}
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
