/**
 * ネイティブ入力（type="number" / type="date"）の Constraint Validation API を用いた
 * silent rejection 検出のための純粋関数群（Issue #33 / 方式=案B）。
 *
 * type="number" / type="date" は、パースできない入力を value="" として返すため、
 * onChange だけを見ていると「画面に残像はあるが state は空」というズレを検出できない。
 * ここでは el.validity（badInput 等）から、ユーザーに提示すべきエラー文言を導出する。
 */

export type FieldKind = 'number' | 'date';

/** getValidityError が参照する validity フラグ（テスト用に切り出した最小の形）。 */
export interface ValidityFlags {
    badInput: boolean;
    stepMismatch: boolean;
    rangeOverflow: boolean;
    rangeUnderflow: boolean;
}

/**
 * validity フラグから表示すべきエラー文言を決める純粋関数（DOM 非依存＝単体テスト容易）。
 * 何も問題が無ければ null を返す。優先度は「不正入力 > 整数違反 > 範囲逸脱」。
 */
export function validityErrorMessage(
    flags: ValidityFlags,
    kind: FieldKind,
): string | null {
    // badInput: 文字混入・存在しない日付・不完全入力など、値に変換できない入力。
    if (flags.badInput) {
        return kind === 'date'
            ? '有効な日付を入力してください'
            : '有効な数値を入力してください';
    }
    // stepMismatch: step="1" の数値欄に小数が入った場合。黙って丸めずに気づかせる。
    if (flags.stepMismatch) {
        return '整数で入力してください';
    }
    // range: min/max を付けた欄（例: birth_date の max=today）での範囲逸脱。
    if (flags.rangeOverflow) {
        return kind === 'date'
            ? '入力できる範囲を超えた日付です'
            : '大きすぎる値です';
    }
    if (flags.rangeUnderflow) {
        return kind === 'date'
            ? '入力できる範囲より前の日付です'
            : '小さすぎる値です';
    }
    return null;
}

/**
 * 入力要素の validity から表示エラー文言を返す（無ければ null）。
 * badInput 時は onChange が発火しないため、呼び出しは onBlur / 送信時に行うこと。
 */
export function getValidityError(
    el: HTMLInputElement,
    opts: { kind: FieldKind },
): string | null {
    const v = el.validity;
    return validityErrorMessage(
        {
            badInput: v.badInput,
            stepMismatch: v.stepMismatch,
            rangeOverflow: v.rangeOverflow,
            rangeUnderflow: v.rangeUnderflow,
        },
        opts.kind,
    );
}
