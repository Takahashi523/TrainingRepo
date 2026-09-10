import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { cn } from "@/lib/utils";
import { ChevronDown, ChevronUp } from "lucide-react";
import { useState } from "react";

interface Props {
    label: string;
    detail: string | null;
    /**
     * 必須（実線）／尚可（点線）の区別。SkillTag と同じ表現に揃える
     * （同じ尚可スキルが一覧・マッチングでは点線、詳細だけ実線になるのを防ぐ）。
     */
    skillType?: "required" | "preferred";
}

const TAG_CLASS =
    "inline-flex items-center gap-1.5 rounded border border-border bg-white px-2 py-0.5 text-xs text-foreground";

export default function SkillTagDetail({
    label,
    detail,
    skillType = "required",
}: Props) {
    const tagClass = cn(TAG_CLASS, skillType === "preferred" && "border-dashed");
    // 開閉そのものは Popover（Radix）が管理する。ここで状態を持つのは
    // トリガーのシェブロンの向きを開閉に追従させるためだけ。
    const [open, setOpen] = useState(false);

    // 詳細を持たないタグは押しても何も起きないため、非対話の span のままにする
    // （押せそうに見えて反応しない要素を作らない）。
    if (!detail) {
        return <span className={tagClass}>{label}</span>;
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            {/* トリガーはシェブロンだけでなくタグ全体。押せる範囲を広げると同時に、
                ポップオーバーの基準がタグの左端になり、記号の真下ではなくタグの直下に出る。 */}
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        tagClass,
                        "cursor-pointer text-left hover:bg-muted/50",
                    )}
                >
                    {label}
                    {/* ボタンの読み上げ名はラベル文字で足りるため、記号は装飾として隠す。
                        aria-expanded / aria-haspopup は Radix がトリガーに付与する。 */}
                    {open ? (
                        <ChevronUp
                            aria-hidden="true"
                            className="h-3 w-3 text-muted-foreground"
                        />
                    ) : (
                        <ChevronDown
                            aria-hidden="true"
                            className="h-3 w-3 text-muted-foreground"
                        />
                    )}
                </button>
            </PopoverTrigger>
            {/* 既定の align="start" / sideOffset={4} が従来の left-0・mt-1 と一致するため指定しない。
                幅は既定の w-72 固定ではなく、内容に応じた可変（min-w-48〜max-w-xs）に戻す。 */}
            <PopoverContent className="w-auto min-w-48 max-w-xs break-words p-2 text-xs leading-relaxed text-muted-foreground">
                {detail}
            </PopoverContent>
        </Popover>
    );
}
