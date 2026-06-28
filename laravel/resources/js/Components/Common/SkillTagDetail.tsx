import { useState } from 'react';

interface Props {
    label: string;
    detail: string | null;
}

export default function SkillTagDetail({ label, detail }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <div className="relative inline-flex flex-col">
            <span className="inline-flex items-center gap-1.5 rounded border border-border bg-white px-2 py-0.5 text-xs text-foreground">
                {label}
                {detail && (
                    <button
                        type="button"
                        className="text-[10px] text-muted-foreground hover:text-foreground"
                        onClick={() => setOpen((v) => !v)}
                    >
                        {open ? '▲' : '▼'}
                    </button>
                )}
            </span>
            {open && detail && (
                <div className="absolute left-0 top-full z-10 mt-1 min-w-48 max-w-xs rounded border border-border bg-white p-2 text-xs leading-relaxed text-muted-foreground shadow-md">
                    {detail}
                </div>
            )}
        </div>
    );
}
