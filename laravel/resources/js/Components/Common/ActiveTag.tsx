import TruncatedText from '@/Components/Common/TruncatedText';
import { X } from 'lucide-react';

interface Props {
    label: string;
    onRemove: () => void;
}

export default function ActiveTag({ label, onRemove }: Props) {
    return (
        <span className="inline-flex max-w-[240px] items-center gap-1 rounded-full border border-foreground/60 bg-muted px-2.5 py-0.5 text-[11px]">
            {/* ラベル（担当営業名など最大255文字）はタグを壊さないよう省略。
                省略時のみホバーで全文を表示する（短いラベルでは no-op）。レビュー指摘 #9 */}
            <TruncatedText text={label} className="min-w-0" />
            <button
                type="button"
                onClick={onRemove}
                className="shrink-0 text-muted-foreground hover:text-foreground"
                aria-label={`${label} を解除`}
            >
                <X className="h-3 w-3" />
            </button>
        </span>
    );
}
