import { forwardRef, type ComponentProps } from 'react';
import { Input } from '@/Components/ui/input';
import { toHalfWidthDigits } from '@/lib/utils';

/**
 * 整数入力用の共通コンポーネント（Issue #67 / 案A）。
 *
 * ネイティブ type="number" は不正入力を value="" に潰して silent rejection を起こし、
 * さらにブラウザ標準の検証ツールチップ（②）が出て他項目の赤字インラインと不統一になる。
 * これを避けるため type="text" とし、入力値をそのまま state に保持する。
 * 不正値（非数値・小数・範囲外）は送信時にサーバ FormRequest（integer / min / max）が
 * 弾き、赤字インラインで提示する（検証はサーバに一本化）。
 *
 * inputMode="numeric" でモバイルの数値キーパッドへ誘導する。対象項目はすべて非負整数
 * （integer, min:0）のため、入力時点で数字以外を弾く（日付欄と挙動をそろえる）。範囲
 * （min/max 等）の検証は引き続きサーバ FormRequest が担い、二重持ちにはしない。
 * value / onChange は文字列で扱う（既存の useForm data 契約を壊さないため）。
 */
interface NumberInputProps
    extends Omit<ComponentProps<'input'>, 'onChange' | 'type' | 'value'> {
    value: string;
    onChange: (value: string) => void;
}

const NumberInput = forwardRef<HTMLInputElement, NumberInputProps>(
    ({ value, onChange, onBlur, inputMode = 'numeric', ...props }, ref) => {
        return (
            <Input
                ref={ref}
                type="text"
                inputMode={inputMode}
                value={value}
                // 全角数字は即時に半角化し、数字以外は除去（非負整数のみ）。range 検証はサーバに委譲。
                onChange={(e) =>
                    onChange(
                        toHalfWidthDigits(e.target.value).replace(/[^0-9]/g, ''),
                    )
                }
                onBlur={(e) => {
                    // フォーカスアウト時に先頭ゼロを外して正準化（"007"→"7" / "00"→"0" / "0"→"0"）。
                    const normalized = value.replace(/^0+(?=\d)/, '');
                    if (normalized !== value) onChange(normalized);
                    onBlur?.(e);
                }}
                {...props}
            />
        );
    },
);
NumberInput.displayName = 'NumberInput';

export default NumberInput;
