import { forwardRef, type ComponentProps } from 'react';
import { Input } from '@/Components/ui/input';

/**
 * 整数入力用の共通コンポーネント（Issue #33 / 案B）。
 *
 * ネイティブ type="number" を維持（数値スピナー・モバイル数値キーパッドを温存）しつつ、
 * - step="1" で小数（1.5 等）は stepMismatch として検出対象にする
 * - blur 時に紛らわしい有効値（01・+5・1e2）を正準の数値文字列へ正規化する
 * silent rejection の検出（badInput 等）は useClientValidity の onBlur / validateAll に委ねる。
 *
 * value / onChange は文字列で扱う（既存の useForm data 契約を壊さないため）。
 */
interface NumberInputProps
    extends Omit<ComponentProps<'input'>, 'onChange' | 'type'> {
    value: string;
    onChange: (value: string) => void;
}

const NumberInput = forwardRef<HTMLInputElement, NumberInputProps>(
    (
        { value, onChange, onBlur, inputMode = 'numeric', step = '1', ...props },
        ref,
    ) => {
        return (
            <Input
                ref={ref}
                type="number"
                inputMode={inputMode}
                step={step}
                value={value}
                // ブラウザ標準の検証バブル（「数字を入力してください」等）は抑止し、
                // 自前のインラインエラー（useClientValidity）に一本化する。
                onInvalid={(e) => e.preventDefault()}
                onChange={(e) => onChange(e.target.value)}
                onBlur={(e) => {
                    // blur 正規化: 妥当な数値なら valueAsNumber の正準表現へ揃える
                    // （01→1 / +5→5 / 1e2→100）。badInput・stepMismatch（小数）・空は対象外。
                    const el = e.currentTarget;
                    if (
                        !el.validity.badInput &&
                        !el.validity.stepMismatch &&
                        el.value !== '' &&
                        Number.isFinite(el.valueAsNumber)
                    ) {
                        const normalized = String(el.valueAsNumber);
                        if (normalized !== el.value) onChange(normalized);
                    }
                    // useClientValidity（fieldProps）由来の onBlur 検証を続けて呼ぶ。
                    onBlur?.(e);
                }}
                {...props}
            />
        );
    },
);
NumberInput.displayName = 'NumberInput';

export default NumberInput;
