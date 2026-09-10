import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
import { WorkTypeOption } from '@/types/engineer';

interface Props {
    workTypes: WorkTypeOption[];
    selected: string[];
    onChange?: (selected: string[]) => void;
    readOnly?: boolean;
}

export default function WorkStyleCheckboxGroup({ workTypes, selected, onChange, readOnly = false }: Props) {
    const toggle = (key: string, checked: boolean) => {
        if (!onChange) return;
        onChange(checked ? [...selected, key] : selected.filter((k) => k !== key));
    };

    return (
        <div className="flex flex-wrap gap-x-6 gap-y-3">
            {workTypes.map((wt) => (
                <div key={wt.key} className="flex items-center gap-2">
                    <Checkbox
                        id={`wt-${wt.key}`}
                        checked={selected.includes(wt.key)}
                        disabled={readOnly}
                        onCheckedChange={
                            !readOnly ? (checked) => toggle(wt.key, !!checked) : undefined
                        }
                    />
                    <Label
                        htmlFor={`wt-${wt.key}`}
                        className={`text-sm font-normal ${readOnly ? 'cursor-default' : 'cursor-pointer'}`}
                    >
                        {wt.name}
                    </Label>
                </div>
            ))}
        </div>
    );
}
