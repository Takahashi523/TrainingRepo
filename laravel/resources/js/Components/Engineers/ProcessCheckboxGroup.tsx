import { cn } from '@/lib/utils';
import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
import { Phase } from '@/types/engineer';

interface Props {
    phases: Phase[];
    values: Record<string, boolean>;
    onChange?: (key: string, checked: boolean) => void;
    readOnly?: boolean;
    className?: string;
}

export default function ProcessCheckboxGroup({ phases, values, onChange, readOnly = false, className }: Props) {
    return (
        <div className={cn('flex flex-wrap gap-x-6 gap-y-3', className)}>
            {phases.map((phase) => (
                <div
                    key={phase.key}
                    className={`flex items-center gap-2 ${readOnly ? 'pointer-events-none' : ''}`}
                >
                    <Checkbox
                        id={`phase-${phase.key}`}
                        checked={!!values[phase.key]}
                        // readOnly（一覧・詳細の表示専用）ではキーボードの Tab 順から外す
                        tabIndex={readOnly ? -1 : undefined}
                        onCheckedChange={
                            !readOnly && onChange
                                ? (checked) => onChange(phase.key, !!checked)
                                : undefined
                        }
                    />
                    <Label
                        htmlFor={`phase-${phase.key}`}
                        className={`text-sm font-normal ${readOnly ? 'cursor-default' : 'cursor-pointer'}`}
                    >
                        {phase.name}
                    </Label>
                </div>
            ))}
        </div>
    );
}
