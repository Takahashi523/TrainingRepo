import TruncatedText from "@/Components/Common/TruncatedText";
import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { useToast } from "@/hooks/use-toast";
import { ConditionValue, SavedSearchItem } from "@/types/savedSearch";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";

interface Props<TConditions extends Record<string, ConditionValue>> {
    open: boolean;
    savedSearches: SavedSearchItem<TConditions>[];
    onClose: () => void;
}

/**
 * 保存済み検索条件の一覧・削除を行うモーダル（管理専用）。
 * 保存は SavedSearchSaveDialog 側の責務とし、ここでは一覧表示・削除のみを扱う。
 * （PR #60 レビュー指摘：「保存」ラベルなのに削除も兼ねる1ボタン2責務の解消）
 */
export default function SavedSearchManageDialog<
    TConditions extends Record<string, ConditionValue>,
>({ open, savedSearches, onClose }: Props<TConditions>) {
    const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const { toast } = useToast();

    // 1件でも削除処理中は、リスト内の削除系ボタンをすべて無効化する（他行の削除トリガーも含む）。
    // deletingId は同時に1件しか保持できないため、行ごとの一致判定だけで無効化すると、
    // 削除中に別の行の削除を開始できてしまい、後から始めた方の deletingId で上書きされて
    // 先に削除中だった行の無効化表示が剥がれてしまう（実際には削除中のまま）という不整合が起きる。
    const isDeleting = deletingId !== null;

    // モーダルを閉じたら削除確認UIをリセットする
    useEffect(() => {
        if (!open) {
            setConfirmDeleteId(null);
        }
    }, [open]);

    const handleDelete = (id: number) => {
        router.delete(`/saved-searches/${id}`, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setDeletingId(id),
            onFinish: () => {
                setDeletingId(null);
                setConfirmDeleteId(null);
            },
            // flash.error は成功レスポンス時のみ拾えるため、リクエスト失敗はここで明示的にトースト表示する
            onError: () =>
                toast({
                    description: "検索条件の削除に失敗しました。時間をおいて再度お試しください。",
                    variant: "destructive",
                    duration: 5000,
                }),
        });
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="sm:max-w-md"
                overlayClassName="bg-white/70 backdrop-blur-sm"
            >
                <DialogHeader>
                    <DialogTitle className="text-base font-bold">
                        保存済み条件の管理
                    </DialogTitle>
                </DialogHeader>

                <div className="min-w-0 py-2">
                    {savedSearches.length === 0 ? (
                        <p className="py-3 text-center text-xs text-muted-foreground">
                            保存済み条件はありません
                        </p>
                    ) : (
                        <ul className="max-h-72 space-y-0.5 overflow-y-auto pr-3">
                            {savedSearches.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-center justify-between gap-2 border-b border-border/50 py-2 text-xs last:border-b-0"
                                >
                                    {/* 条件名（最大100文字）は行を壊さないよう1行省略する。
                                        素の truncate だと省略された名前を確認する手段が無くなるため、
                                        省略時のみホバーで全文を出す TruncatedText を使う。 */}
                                    <TruncatedText
                                        text={item.name}
                                        className="min-w-0 flex-1"
                                    />
                                    {confirmDeleteId === item.id ? (
                                        <div className="flex shrink-0 items-center gap-1.5">
                                            <span className="text-[11px] text-destructive">
                                                削除しますか？
                                            </span>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="destructive"
                                                className="h-6 px-2 text-[11px]"
                                                disabled={isDeleting}
                                                onClick={() => handleDelete(item.id)}
                                            >
                                                {deletingId === item.id ? "削除中..." : "削除"}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="h-6 px-2 text-[11px]"
                                                disabled={isDeleting}
                                                onClick={() => setConfirmDeleteId(null)}
                                            >
                                                キャンセル
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="h-6 shrink-0 border-destructive px-2 text-[11px] text-destructive hover:bg-destructive/5"
                                            disabled={isDeleting}
                                            onClick={() => setConfirmDeleteId(item.id)}
                                        >
                                            削除
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        閉じる
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
