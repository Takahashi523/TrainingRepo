import { Button } from "@/Components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/Components/ui/dialog";
import { Input } from "@/Components/ui/input";
import { useToast } from "@/hooks/use-toast";
import { ConditionValue } from "@/types/savedSearch";
import { router } from "@inertiajs/react";
import { useEffect, useState } from "react";

// 保存名の上限（DB設計書 saved_searches.name VARCHAR(100) に合わせる）
const NAME_MAX_LENGTH = 100;

interface Props<TConditions extends Record<string, ConditionValue>> {
    open: boolean;
    searchType: "engineer" | "project";
    /** 保存ボタン押下時に送る「今の絞り込み状態」 */
    currentConditions: TConditions;
    /** モーダル上部に表示する、現在の絞り込み条件のタグ（表示専用） */
    activeTagLabels: string[];
    onClose: () => void;
}

/**
 * 検索条件の保存を行うモーダル（保存専用）。
 * 保存済み条件の一覧・削除は SavedSearchManageDialog 側の責務とし、ここでは保存のみを扱う。
 * （PR #60 レビュー指摘：「保存」ラベルなのに削除も兼ねる1ボタン2責務の解消。
 *  呼び出し元でこのモーダルを開くボタンは絞り込みがある時だけ表示されるため、
 *  ここに到達する時点で activeTagLabels は必ず1件以上ある前提）
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

export default function SavedSearchSaveDialog<
    TConditions extends Record<string, ConditionValue>,
>({ open, searchType, currentConditions, activeTagLabels, onClose }: Props<TConditions>) {
    const [name, setName] = useState("");
    const [isSaving, setIsSaving] = useState(false);
    const { toast } = useToast();

    // モーダルを閉じたら入力内容をリセットする
    useEffect(() => {
        if (!open) {
            setName("");
        }
    }, [open]);

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
                // 保存できたら閉じる（保存専用モーダルになったため、開いたままにする理由がない）
                onSuccess: () => {
                    setName("");
                    onClose();
                },
                // flash.error は成功レスポンス時のみ拾えるため、422（バリデーション失敗）等の
                // リクエスト失敗はここで明示的にトースト表示する
                onError: () =>
                    toast({
                        description: "検索条件の保存に失敗しました。時間をおいて再度お試しください。",
                        variant: "destructive",
                        duration: 5000,
                    }),
            },
        );
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

                <div className="min-w-0 space-y-4 py-2">
                    {/* 現在の絞り込み条件（表示専用） */}
                    <div>
                        <p className="mb-1.5 text-xs font-semibold text-muted-foreground">
                            保存する絞り込み条件
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {activeTagLabels.map((label, i) => (
                                <span
                                    key={i}
                                    className="rounded border border-border bg-muted/50 px-2 py-0.5 text-xs"
                                >
                                    {label}
                                </span>
                            ))}
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
