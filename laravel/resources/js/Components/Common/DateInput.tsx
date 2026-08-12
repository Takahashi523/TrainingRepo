import { forwardRef, type ComponentProps } from 'react';
import { Input } from '@/Components/ui/input';

/**
 * 日付入力用の共通コンポーネント（Issue #33 / 案B）。
 *
 * ネイティブ type="date" を維持（カレンダーピッカーを温存）する。存在しない日付（2026/02/30）や
 * 不完全入力は value="" になり onChange が発火しないため、検出は useClientValidity の
 * onBlur / validateAll（validity.badInput）に委ねる。min/max（例: birth_date の max=today）は
 * passthrough し、range 逸脱も同フックで拾う。
 *
 * value / onChange は 'YYYY-MM-DD' 文字列（ネイティブ date と同一契約）で扱う。
 */
interface DateInputProps
    extends Omit<ComponentProps<'input'>, 'onChange' | 'type'> {
    value: string;
    onChange: (value: string) => void;
}

const DateInput = forwardRef<HTMLInputElement, DateInputProps>(
    ({ value, onChange, ...props }, ref) => {
        return (
            <Input
                ref={ref}
                type="date"
                value={value}
                // ブラウザ標準の検証バブルは抑止し、自前のインラインエラーに一本化する。
                onInvalid={(e) => e.preventDefault()}
                onChange={(e) => onChange(e.target.value)}
                {...props}
            />
        );
    },
);
DateInput.displayName = 'DateInput';

export default DateInput;
