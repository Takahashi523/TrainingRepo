import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
import { Phase } from '@/types/engineer';

interface Props {
    phases: Phase[];
    values: Record<string, boolean>;
    onChange?: (key: string, checked: boolean) => void;
    readOnly?: boolean;
}

export default function ProcessCheckboxGroup({ phases, values, onChange, readOnly = false }: Props) {
    return (
        <div className="flex flex-wrap gap-x-6 gap-y-3">
            {phases.map((phase) => (
                <div key={phase.key} className="flex items-center gap-2">
                    <Checkbox
                        id={`phase-${phase.key}`}
                        checked={!!values[phase.key]}
                        disabled={readOnly}
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
