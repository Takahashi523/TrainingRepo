import { ChevronDown, ChevronUp } from "lucide-react";
import { useEffect, useRef, useState } from "react";

interface Props {
    label: string;
    detail: string | null;
}

export default function SkillTagDetail({ label, detail }: Props) {
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        function handleClickOutside(event: MouseEvent) {
            if (
                containerRef.current &&
                !containerRef.current.contains(event.target as Node)
            ) {
                setOpen(false);
            }
        }

        document.addEventListener("mousedown", handleClickOutside);
        return () => {
            document.removeEventListener("mousedown", handleClickOutside);
        };
    }, [open]);

    return (
        <div ref={containerRef} className="relative inline-flex flex-col">
            <span className="inline-flex items-center gap-1.5 rounded border border-border bg-white px-2 py-0.5 text-xs text-foreground">
                {label}
                {detail && (
                    <button
                        type="button"
                        // アイコンのみのボタンのため、開閉状態を aria で明示する
                        aria-label={open ? "詳細を閉じる" : "詳細を表示"}
                        aria-expanded={open}
                        className="text-muted-foreground hover:text-foreground"
                        onClick={() => setOpen((v) => !v)}
                    >
                        {open ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )}
                    </button>
                )}
            </span>
            {open && detail && (
                <div className="absolute left-0 top-full z-10 mt-1 min-w-48 max-w-xs break-words rounded border border-border bg-white p-2 text-xs leading-relaxed text-muted-foreground shadow-md">
                    {detail}
                </div>
            )}
        </div>
    );
}
