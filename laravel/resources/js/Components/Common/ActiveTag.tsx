import { X } from 'lucide-react';

interface Props {
    label: string;
    onRemove: () => void;
}

export default function ActiveTag({ label, onRemove }: Props) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full border border-foreground/60 bg-muted px-2.5 py-0.5 text-[11px]">
            {label}
            <button
                type="button"
                onClick={onRemove}
                className="text-muted-foreground hover:text-foreground"
                aria-label={`${label} を解除`}
            >
                <X className="h-3 w-3" />
            </button>
        </span>
    );
}
