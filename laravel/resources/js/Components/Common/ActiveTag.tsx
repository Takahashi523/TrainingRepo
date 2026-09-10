import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import TruncatedText from '@/Components/Common/TruncatedText';
import { X } from 'lucide-react';

interface Props {
    label: string;
    onRemove: () => void;
}

export default function ActiveTag({ label, onRemove }: Props) {
    return (
        <Badge
            variant="outline"
            className="max-w-[240px] gap-1 border-foreground/60 bg-muted py-0.5 pl-2.5 pr-1 text-[11px] font-normal"
        >
            {/* ラベル（担当営業名など最大255文字）はタグを壊さないよう省略。
                省略時のみホバーで全文を表示する（短いラベルでは no-op）。レビュー指摘 #9 */}
            <TruncatedText text={label} className="min-w-0" />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={onRemove}
                className="h-4 w-4 shrink-0 rounded-full text-muted-foreground hover:bg-transparent hover:text-foreground"
                aria-label={`${label} を解除`}
            >
                <X className="h-3 w-3" />
            </Button>
        </Badge>
    );
}
