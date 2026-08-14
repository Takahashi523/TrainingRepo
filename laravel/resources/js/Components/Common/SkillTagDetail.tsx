import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/Components/ui/popover";
import { ChevronDown, ChevronUp } from "lucide-react";
import { useState } from "react";

interface Props {
    label: string;
    detail: string | null;
}

export default function SkillTagDetail({ label, detail }: Props) {
    // 開閉そのものは Popover（Radix）が管理する。ここで状態を持つのは
    // トリガーのシェブロンの向きを開閉に追従させるためだけ。
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <span className="inline-flex items-center gap-1.5 rounded border border-border bg-white px-2 py-0.5 text-xs text-foreground">
                {label}
                {detail && (
                    <PopoverTrigger asChild>
                        <button
                            type="button"
                            // アイコンのみのボタンのため名前を補う。
                            // aria-expanded / aria-haspopup は Radix がトリガーに付与する。
                            aria-label={open ? "詳細を閉じる" : "詳細を表示"}
                            className="text-muted-foreground hover:text-foreground"
                        >
                            {open ? (
                                <ChevronUp className="h-3 w-3" />
                            ) : (
                                <ChevronDown className="h-3 w-3" />
                            )}
                        </button>
                    </PopoverTrigger>
                )}
            </span>
            {/* 既定の align="start" / sideOffset={4} が従来の left-0・mt-1 と一致するため指定しない。
                幅は既定の w-72 固定ではなく、内容に応じた可変（min-w-48〜max-w-xs）に戻す。 */}
            <PopoverContent className="w-auto min-w-48 max-w-xs break-words p-2 text-xs leading-relaxed text-muted-foreground">
                {detail}
            </PopoverContent>
        </Popover>
    );
}
