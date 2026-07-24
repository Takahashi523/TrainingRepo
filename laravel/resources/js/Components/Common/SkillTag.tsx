import { cn } from '@/lib/utils';

interface Props {
    label: string;
    skillType?: 'required' | 'preferred';
}

export default function SkillTag({ label, skillType = 'required' }: Props) {
    return (
        <span
            className={cn(
                'max-w-full break-words rounded border bg-white px-2 py-0.5 text-[11px]',
                skillType === 'preferred' ? 'border-dashed border-border' : 'border-border',
            )}
        >
            {label}
        </span>
    );
}
