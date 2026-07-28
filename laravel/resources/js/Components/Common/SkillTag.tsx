import { cn } from '@/lib/utils';

interface Props {
    label: string;
    skillType?: 'required' | 'preferred';
    /**
     * タグのスタイルを外側から上書きする（例：`text-muted-foreground`）。
     * 未指定時は既定の黒（foreground）。色などを呼び出し側で調整したいときに渡す。
     */
    className?: string;
}

export default function SkillTag({ label, skillType = 'required', className }: Props) {
    return (
        <span
            className={cn(
                'rounded border bg-white px-2 py-0.5 text-[11px] text-foreground',
                skillType === 'preferred' ? 'border-dashed border-border' : 'border-border',
                className,
            )}
        >
            {label}
        </span>
    );
}
