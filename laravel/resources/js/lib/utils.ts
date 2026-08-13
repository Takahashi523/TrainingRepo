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
