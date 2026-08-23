import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * 全角数字（０-９）を半角（0-9）へ変換する。
 * IME が全角モードのまま数字入力されても、数値・日付欄で即時に半角へ正規化するために使う。
 */
export function toHalfWidthDigits(s: string): string {
    return s.replace(/[０-９]/g, (ch) =>
        String.fromCharCode(ch.charCodeAt(0) - 0xfee0),
    );
}

/**
 * 'YYYY-MM-DD' かつ実在する日付なら true（2026-02-30 等のロールオーバーは false）。
 *
 * `DateInput` は打鍵ごとに入力途中の文字列（'2' / '2026-' / '2026-08-0'）をそのまま
 * onChange で返すため、「送ってよい完成値か」を呼び出し側が判定する必要がある。
 * `DateInput` 内部（隠しネイティブ date への反映）と、入力のたびにサーバーへ問い合わせる
 * ライブフィルタ（進捗管理・完了済みタブ）の双方で同じ判定を使う。
 */
export function isValidYmd(s: string): boolean {
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
