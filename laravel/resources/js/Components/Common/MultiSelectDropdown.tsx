import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface MultiSelectOption {
    value: string;
    label: string;
}

interface Props {
    label: string;
    options: MultiSelectOption[];
    selected: string[];
    onChange: (next: string[]) => void;
}

export default function MultiSelectDropdown({ label, options, selected, onChange }: Props) {
    const [open, setOpen] = useState(false);
    const wrapRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const handler = (e: MouseEvent) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [open]);

    const toggle = (value: string) => {
        const next = selected.includes(value)
            ? selected.filter((v) => v !== value)
            : [...selected, value];
        onChange(next);
    };

    return (
        <div ref={wrapRef} className="relative inline-block">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className={cn(
                    'inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs font-semibold transition-colors',
                    open
                        ? 'border-foreground bg-muted'
                        : 'border-input bg-white hover:bg-muted/50',
                )}
            >
                <span>{label}</span>
                {selected.length > 0 && (
                    <span className="rounded-full bg-primary px-1.5 text-[10px] font-bold text-primary-foreground">
                        {selected.length}
                    </span>
                )}
                <ChevronDown className="h-3 w-3" />
            </button>
            {open && (
                <div className="absolute left-0 top-full z-20 mt-1 min-w-[180px] rounded-md border border-input bg-white py-1.5 shadow-md">
                    {options.map((opt) => {
                        const checked = selected.includes(opt.value);
                        return (
                            <label
                                key={opt.value}
                                className="flex cursor-pointer items-center gap-2 px-3 py-1.5 text-xs hover:bg-muted/50"
                            >
                                <input
                                    type="checkbox"
                                    className="h-3.5 w-3.5 cursor-pointer"
                                    checked={checked}
                                    onChange={() => toggle(opt.value)}
                                />
                                <span>{opt.label}</span>
                            </label>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
