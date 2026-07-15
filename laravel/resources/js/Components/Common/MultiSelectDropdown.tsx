import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-react';

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
    const toggle = (value: string) => {
        const next = selected.includes(value)
            ? selected.filter((v) => v !== value)
            : [...selected, value];
        onChange(next);
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className={cn(
                        'h-8 gap-1.5 bg-white px-2.5 text-xs font-semibold [&_svg]:size-3',
                        selected.length > 0 && 'bg-muted',
                    )}
                >
                    <span>{label}</span>
                    {selected.length > 0 && (
                        <span className="rounded-full bg-primary px-1.5 text-[10px] font-bold text-primary-foreground">
                            {selected.length}
                        </span>
                    )}
                    <ChevronDown />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-auto min-w-[180px] p-1.5">
                {options.map((opt) => {
                    const checked = selected.includes(opt.value);
                    return (
                        <label
                            key={opt.value}
                            className="flex cursor-pointer items-center gap-2 rounded px-3 py-1.5 text-xs hover:bg-muted/50"
                        >
                            <Checkbox
                                checked={checked}
                                onCheckedChange={() => toggle(opt.value)}
                                className="h-3.5 w-3.5 [&_svg]:size-3"
                            />
                            <span>{opt.label}</span>
                        </label>
                    );
                })}
            </PopoverContent>
        </Popover>
    );
}
