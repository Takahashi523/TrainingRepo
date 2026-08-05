import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/Components/ui/select";
import { ConditionValue, SavedSearchItem } from "@/types/savedSearch";
import { useState } from "react";

interface Props<TConditions extends Record<string, ConditionValue>> {
    savedSearches: SavedSearchItem<TConditions>[];
    onApply: (conditions: TConditions) => void;
}

/**
 * 保存済み検索条件を選んで呼び出すだけのプルダウン（WF_03/WF_06の「保存済み条件」selectに対応）。
 * 保存・削除の管理は SavedSearchManageDialog 側の責務とし、ここでは適用のみを扱う。
 */
export default function SavedSearchSelect<
    TConditions extends Record<string, ConditionValue>,
>({ savedSearches, onApply }: Props<TConditions>) {
    const [selectedId, setSelectedId] = useState("");

    const handleChange = (id: string) => {
        setSelectedId(id);
        const item = savedSearches.find((s) => String(s.id) === id);
        if (item) onApply(item.conditions);
    };

    return (
        <Select value={selectedId} onValueChange={handleChange}>
            <SelectTrigger className="h-8 w-[200px] bg-white text-xs">
                <SelectValue placeholder="条件を選択して呼び出す" />
            </SelectTrigger>
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
