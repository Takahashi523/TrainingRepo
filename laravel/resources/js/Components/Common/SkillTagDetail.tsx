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
                        className="text-[10px] text-muted-foreground hover:text-foreground"
                        onClick={() => setOpen((v) => !v)}
                    >
                        {open ? "▲" : "▼"}
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
