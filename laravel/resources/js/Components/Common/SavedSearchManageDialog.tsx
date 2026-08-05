import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import { ConditionValue, SavedSearchItem } from "@/types/savedSearch";
import { router } from "@inertiajs/react";
import { useState } from "react";

// 保存名の上限（DB設計書 saved_searches.name VARCHAR(100) に合わせる）
const NAME_MAX_LENGTH = 100;

interface Props<TConditions extends Record<string, ConditionValue>> {
    open: boolean;
    searchType: "engineer" | "project";
    savedSearches: SavedSearchItem<TConditions>[];
    /** 保存ボタン押下時に送る「今の絞り込み状態」 */
    currentConditions: TConditions;
    /** モーダル上部に表示する、現在の絞り込み条件のタグ（表示専用） */
    activeTagLabels: string[];
    onClose: () => void;
}

/**
 * 検索条件の保存・保存済み一覧の管理（削除）を行うモーダル。
 * WF_03/WF_06 の「条件保存モーダル」に対応。適用（呼び出し）は SavedSearchSelect 側の責務。
 *
 * 条件名はWF通り任意入力。未入力の場合は、現在の絞り込みタグから自動生成した名前を
 * サーバーに送信する（API仕様書で name は必須のため、フロント側で必ず非空文字列にして送る）。
 */
function buildAutoName(tagLabels: string[]): string {
    if (tagLabels.length === 0) return "無題の検索条件";

    // タグを1つずつ足していき、上限を超える手前で止める（単語の途中で切れるのを防ぐ）
    let result = "";
    for (const label of tagLabels) {
        const next = result ? `${result} × ${label}` : label;
        if (next.length > NAME_MAX_LENGTH) {
            return result ? `${result}…` : `${label.slice(0, NAME_MAX_LENGTH - 1)}…`;
        }
        result = next;
    }
    return result;
}
export default function SavedSearchManageDialog<
    TConditions extends Record<string, ConditionValue>,
>({
    open,
    searchType,
    savedSearches,
    currentConditions,
    activeTagLabels,
    onClose,
}: Props<TConditions>) {
    const [name, setName] = useState("");
    const [isSaving, setIsSaving] = useState(false);
    const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);

    const handleSave = () => {
        if (isSaving) return;

        // 未入力ならタグから自動生成した名前を使う（WF準拠。APIには常に非空文字列を送る）
        const finalName = name.trim() || buildAutoName(activeTagLabels);

        router.post(
            "/saved-searches",
            {
                name: finalName,
                search_type: searchType,
                conditions: currentConditions,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsSaving(true),
                onFinish: () => setIsSaving(false),
                // 保存できたら次回のために入力欄をクリアする（モーダルは開いたまま：WF準拠）
                onSuccess: () => setName(""),
            },
        );
    };

    const handleDelete = (id: number) => {
        router.delete(`/saved-searches/${id}`, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setDeletingId(id),
            onFinish: () => {
                setDeletingId(null);
                setConfirmDeleteId(null);
            },
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
                        検索条件を保存
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {/* 現在の絞り込み条件（表示専用） */}
                    <div>
                        <p className="mb-1.5 text-xs font-semibold text-muted-foreground">
                            保存する絞り込み条件
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {activeTagLabels.length === 0 ? (
                                <span className="text-xs text-muted-foreground">
                                    絞り込み条件は設定されていません
                                </span>
                            ) : (
                                activeTagLabels.map((label, i) => (
                                    <span
                                        key={i}
                                        className="rounded border border-border bg-muted/50 px-2 py-0.5 text-xs"
                                    >
                                        {label}
                                    </span>
                                ))
                            )}
                        </div>
                    </div>

                    {/* 条件名入力 */}
                    <div>
                        <p className="mb-1.5 text-xs font-semibold text-muted-foreground">
                            条件名（任意）
                        </p>
                        <div className="flex items-center gap-1.5">
                            <Input
                                type="text"
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="例：Java × 提案可 × フルリモート"
                                maxLength={NAME_MAX_LENGTH}
                                className="h-8 text-xs"
                            />
                            <Button
                                type="button"
                                size="sm"
                                className="h-8 shrink-0 px-2.5 text-xs"
                                disabled={isSaving}
                                onClick={handleSave}
                            >
                                保存する
                            </Button>
                        </div>
                        <p className="mt-1 text-[11px] text-muted-foreground">
                            未入力の場合はタグの組み合わせを自動で条件名に使用します
                        </p>
                    </div>

                    {/* 保存済み条件の管理（削除は1クリックでは実行せず、インライン確認を挟む） */}
                    <div className="border-t border-border pt-3">
                        <p className="mb-1.5 text-xs font-semibold text-muted-foreground">
                            保存済み条件の管理
                        </p>
                        {savedSearches.length === 0 ? (
                            <p className="py-3 text-center text-xs text-muted-foreground">
                                保存済み条件はありません
                            </p>
                        ) : (
                            <ul className="max-h-52 space-y-0.5 overflow-y-auto">
                                {savedSearches.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center justify-between gap-2 border-b border-border/50 py-2 text-xs last:border-b-0"
                                    >
                                        <span className="min-w-0 flex-1 truncate">
                                            {item.name}
                                        </span>
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
                                                    disabled={deletingId === item.id}
                                                    onClick={() => handleDelete(item.id)}
                                                >
                                                    削除
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-6 px-2 text-[11px]"
                                                    onClick={() => setConfirmDeleteId(null)}
                                                >
                                                    取消
                                                </Button>
                                            </div>
                                        ) : (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="h-6 shrink-0 px-2 text-[11px]"
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
