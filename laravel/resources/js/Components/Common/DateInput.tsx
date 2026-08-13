import { Calendar as CalendarIcon } from 'lucide-react';
import { forwardRef, useRef, type ComponentProps } from 'react';

import { Input } from '@/Components/ui/input';
import { cn, toHalfWidthDigits } from '@/lib/utils';

/**
 * 日付入力用の共通コンポーネント（Issue #67 / 案B：テキスト欄＋ネイティブピッカー）。
 *
 * ネイティブ type="date" を「見える欄」にすると、不正入力を value="" に潰す silent rejection と、
 * ブラウザ標準の検証ツールチップ（②）が JS で抑止できない不統一が生じる。
 * そこで見える欄は type="text"（手入力可）にして入力値をそのまま保持し、
 * カレンダーアイコン押下時だけ、隠した type="date" の showPicker() で**ブラウザ標準の
 * 日付ピッカー**（年リスト＋月グリッド等の OS/ブラウザ既定 UI）を開く。
 *
 * 隠し date はユーザーが直接打鍵しないため badInput（②）にならず、選んだ妥当値のみを
 * YYYY-MM-DD で親へ返す。不正日付（手入力）は送信時にサーバ FormRequest（date /
 * before_or_equal:today）が弾き、赤字インラインで提示する。
 *
 * 表示はスラッシュ区切り 'YYYY/MM/DD'（入力中に / を自動挿入・数字のみ受付）。
 * value / onChange の契約は 'YYYY-MM-DD' 文字列（空文字可）のまま維持し、表示時に -→/、
 * onChange 時に /→- で橋渡しする（サーバ・年齢計算・隠しネイティブ date は YYYY-MM-DD 前提）。
 * min / max（'YYYY-MM-DD'）は隠し date にそのまま渡し、ネイティブピッカーの選択可否に委ねる。
 */
interface DateInputProps
    extends Omit<
        ComponentProps<'input'>,
        'onChange' | 'type' | 'value' | 'min' | 'max'
    > {
    value: string;
    onChange: (value: string) => void;
    min?: string;
    max?: string;
}

/**
 * 数字だけを取り出して 'YYYY/MM/DD' 形にマスクする（グループ完了時に / を自動挿入）。
 * 例: "2026" → "2026/" / "202608" → "2026/08/" / "20260809" → "2026/08/09"。
 * 年（4桁）・月（6桁）が埋まった時点で末尾に / を付ける。
 */
function maskYmdSlash(input: string): string {
    const digits = input.replace(/\D/g, '').slice(0, 8);
    let out = digits.slice(0, 4);
    if (digits.length >= 4) out += '/';
    out += digits.slice(4, 6);
    if (digits.length >= 6) out += '/';
    out += digits.slice(6, 8);
    return out;
}

/** 'YYYY-MM-DD' かつ実在日付なら true（2026-02-30 等のロールオーバーは false）。 */
function isValidYmd(s: string): boolean {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (!m) return false;
    const y = Number(m[1]);
    const mo = Number(m[2]);
    const d = Number(m[3]);
    const date = new Date(y, mo - 1, d);
    return (
        date.getFullYear() === y &&
        date.getMonth() === mo - 1 &&
        date.getDate() === d
    );
}

const DateInput = forwardRef<HTMLInputElement, DateInputProps>(
    ({ value, onChange, min, max, className, ...props }, ref) => {
        const hiddenDateRef = useRef<HTMLInputElement>(null);

        // カレンダーアイコン押下で、隠し date のネイティブピッカーを開く。
        const openNativePicker = () => {
            const el = hiddenDateRef.current;
            if (!el) return;
            try {
                el.showPicker();
            } catch {
                // showPicker 非対応/不可の環境ではフォーカスにフォールバック。
                el.focus();
            }
        };

        return (
            <div className="relative inline-flex w-fit items-center">
                {/* w-fit: 親が flex-col（align-items:stretch）でも全幅に伸びず入力欄幅に収める。 */}
                <Input
                    ref={ref}
                    type="text"
                    inputMode="numeric"
                    // 慣習どおり整形後の形式を提示（/ は自動挿入。手入力の / も吸収される）。
                    placeholder="YYYY/MM/DD"
                    // 表示はスラッシュ区切り。内部の value 契約は 'YYYY-MM-DD' のまま
                    // （サーバ・年齢計算・隠しネイティブ date はハイフン形式が前提）。
                    value={value.replace(/-/g, '/')}
                    onChange={(e) => {
                        const prevDisplay = value.replace(/-/g, '/');
                        // 全角数字は即時に半角化してからマスク処理する。
                        let raw = toHalfWidthDigits(e.target.value);
                        // 末尾スラッシュを backspace した場合（"2026/" → "2026"）は、
                        // グループ末尾の数字も1つ削って戻す（再挿入で消せなくなるのを防ぐ）。
                        if (
                            raw.length < prevDisplay.length &&
                            prevDisplay.endsWith('/') &&
                            raw === prevDisplay.slice(0, -1)
                        ) {
                            raw = raw.slice(0, -1);
                        }
                        onChange(maskYmdSlash(raw).replace(/\//g, '-'));
                    }}
                    // pr-9 は className の後に置き、呼び出し側の px-* に打ち消されないようにする。
                    className={cn(className, 'pr-9')}
                    {...props}
                />
                <button
                    type="button"
                    aria-label="カレンダーを開く"
                    onClick={openNativePicker}
                    className="absolute right-1 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <CalendarIcon className="h-4 w-4" />
                </button>
                {/* 隠しネイティブ date：打鍵は受けず（tabIndex=-1）、showPicker() 用。
                    妥当な日付のみ value に反映して badInput（②）を避ける。min/max はネイティブに委ねる。 */}
                <input
                    ref={hiddenDateRef}
                    type="date"
                    aria-hidden="true"
                    tabIndex={-1}
                    value={isValidYmd(value) ? value : ''}
                    min={min}
                    max={max}
                    onChange={(e) => onChange(e.target.value)}
                    className="pointer-events-none absolute right-1 top-1/2 h-7 w-7 -translate-y-1/2 opacity-0"
                />
            </div>
        );
    },
);
DateInput.displayName = 'DateInput';

export default DateInput;
