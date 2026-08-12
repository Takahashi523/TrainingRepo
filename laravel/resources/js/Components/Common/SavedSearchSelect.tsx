import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { ConditionValue, SavedSearchItem } from "@/types/savedSearch";

interface Props<TConditions extends Record<string, ConditionValue>> {
    savedSearches: SavedSearchItem<TConditions>[];
    onApply: (conditions: TConditions) => void;
}

/**
 * 保存済み検索条件を選んで呼び出すだけのプルダウン（WF_03/WF_06の「保存済み条件」selectに対応）。
 * 保存・削除の管理は SavedSearchManageDialog 側の責務とし、ここでは適用のみを扱う。
 *
 * value は常に "" 固定（選択状態を保持しない）。
 * 選択中のIDを保持すると、削除で該当項目が消えたときに空表示のまま固まったり、
 * 一覧の外で条件を手動変更した後に同じ項目を選び直しても Radix Select が
 * 「値が変化していない」と判定して onValueChange を発火しない、という問題が起きるため、
 * そもそも「選ぶたびに毎回 "" → 選択したID という変化」として扱い、常に発火させる。
 */
export default function SavedSearchSelect<
    TConditions extends Record<string, ConditionValue>,
>({ savedSearches, onApply }: Props<TConditions>) {
    const handleChange = (id: string) => {
        const item = savedSearches.find((s) => String(s.id) === id);
        if (item) onApply(item.conditions);
    };

    return (
        <Select value="" onValueChange={handleChange}>
            <SelectTrigger className="h-8 w-[200px] bg-white text-xs">
                <SelectValue placeholder="条件を選択して呼び出す" />
            </SelectTrigger>
            {/* 条件名は最長でも100文字だが、省略表示（…）はしない。
                ここは選択のためだけの一時的なポップオーバーで、他の操作要素を巻き込まないため、
                多少幅が広がったり折り返されたりしても実害がない（モーダル側とは異なる判断）。 */}
            <SelectContent>
                {savedSearches.length === 0 ? (
                    <div className="px-2 py-1.5 text-xs text-muted-foreground">
                        保存された検索条件はありません
                    </div>
                ) : (
                    savedSearches.map((item) => (
                        <SelectItem key={item.id} value={String(item.id)}>
                            {item.name}
                        </SelectItem>
                    ))
                )}
            </SelectContent>
        </Select>
    );
}
