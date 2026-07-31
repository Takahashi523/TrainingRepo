import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Button } from '@/Components/ui/button';
import {
    csvFieldLabel,
    CsvResource,
    ImportError,
    summarizeImportErrors,
} from '@/types/csv';
import { AlertOctagon } from 'lucide-react';

interface Props {
    /** 表示フラグ。 */
    open: boolean;
    /** 対象リソース（field→日本語ラベル解決に使う）。 */
    resource: CsvResource;
    /** 構造化エラー一覧（1つの (row, field) につき messages を配列で持つ）。 */
    errors: ImportError[];
    /** 閉じる（✕／閉じるボタン／Esc）。結果自体は破棄しない（保持）＝呼び出し側が state を残す。 */
    onClose: () => void;
}

/**
 * インポート結果（エラー）モーダル（WF_11 v1.2 / 詳細設計）。
 *
 * - 全行ロールバック方式のため「エラー行数・メッセージ件数」を先頭サマリに出す（部分成功件数は出さない）。
 * - エラー表は (行, 項目) を1行にまとめ、messages を「・」箇条書きで縦に並べる。
 * - 件数が多くてもモーダルが破綻しないよう、表本体は max-height のスクロール領域に収める。
 * - 背景幕はプロジェクト既定の明色ブラー（overlayClassName で bg-black/80 をオプトアウト）。
 * - エラー行コピー時の誤爆閉じを防ぐため、オーバーレイ外クリックでは閉じない
 *   （onInteractOutside を preventDefault）。閉じるのは「閉じる」ボタン／✕／Esc のみ。
 */
export default function ImportResultModal({ open, resource, errors, onClose }: Props) {
    const { errorRowCount, messageCount } = summarizeImportErrors(errors);

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                overlayClassName="bg-white/70 backdrop-blur-sm"
                onInteractOutside={(e) => e.preventDefault()}
                className="max-w-2xl gap-0 p-0"
            >
                <DialogHeader className="border-b border-border px-5 py-3.5">
                    <DialogTitle className="flex items-center gap-2 text-base font-bold text-destructive">
                        <AlertOctagon className="h-4 w-4" />
                        インポートに失敗しました
                    </DialogTitle>
                </DialogHeader>

                {/* 件数サマリ（全取消・全行ロールバックを明示） */}
                <div className="border-b border-destructive/20 bg-destructive/5 px-5 py-3 text-xs leading-relaxed text-destructive">
                    {errorRowCount > 0
                        ? `${errorRowCount}行でエラー（メッセージ ${messageCount}件）。`
                        : `メッセージ ${messageCount}件のエラーがあります。`}
                    すべての行の取り込みを取り消しました。
                    <br />
                    CSV を修正して再アップロードしてください。
                </div>

                {/* エラー表（スクロール可能領域） */}
                <div className="max-h-[45vh] overflow-y-auto">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow className="bg-muted hover:bg-muted">
                                <TableHead className="h-auto w-20 px-4 py-2 text-[11px] font-bold text-muted-foreground">
                                    行
                                </TableHead>
                                <TableHead className="h-auto w-40 px-4 py-2 text-[11px] font-bold text-muted-foreground">
                                    項目
                                </TableHead>
                                <TableHead className="h-auto px-4 py-2 text-[11px] font-bold text-muted-foreground">
                                    エラー内容
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {errors.map((e, i) => (
                                <TableRow key={`${e.row ?? 'null'}-${e.field ?? 'null'}-${i}`}>
                                    {/* (行, 項目) は1エントリ＝1行として1回だけ表示 */}
                                    <TableCell className="whitespace-nowrap px-4 py-2 align-top font-bold text-destructive">
                                        {e.row === null ? '—' : `${e.row}行目`}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap px-4 py-2 align-top text-muted-foreground">
                                        {csvFieldLabel(resource, e.field)}
                                    </TableCell>
                                    <TableCell className="px-4 py-2 align-top text-foreground">
                                        {/* messages は「・」箇条書きで縦に列挙（読点でのベタ連結はしない） */}
                                        <ul className="space-y-0.5">
                                            {e.messages.map((m, j) => (
                                                <li key={j} className="flex gap-1">
                                                    <span aria-hidden>・</span>
                                                    <span>{m}</span>
                                                </li>
                                            ))}
                                        </ul>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <DialogFooter className="border-t border-border px-5 py-3">
                    <Button type="button" onClick={onClose} className="h-9">
                        閉じる
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
