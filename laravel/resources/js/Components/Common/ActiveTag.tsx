import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { X } from 'lucide-react';

interface Props {
    label: string;
    onRemove: () => void;
}

export default function ActiveTag({ label, onRemove }: Props) {
    return (
        <Badge
            variant="outline"
            className="gap-1 border-foreground/60 bg-muted py-0.5 pl-2.5 pr-1 text-[11px] font-normal"
        >
            {label}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onRemove}
                className="h-4 w-4 rounded-full text-muted-foreground hover:bg-transparent hover:text-foreground"
                aria-label={`${label} を解除`}
            >
                <X className="h-3 w-3" />
            </Button>
        </Badge>
    );
}
