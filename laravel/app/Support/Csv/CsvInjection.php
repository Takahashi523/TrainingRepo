<?php

namespace App\Support\Csv;

/**
 * CSV インジェクション（数式インジェクション）対策の共通ヘルパ（O-4 確定）。
 *
 * エクスポートした CSV は Excel / Google スプレッドシート / LibreOffice / Numbers 等の
 * 任意の表計算アプリで開かれうる。セル値が数式トリガー文字（= + - @ / タブ / CR）で始まると
 * 数式実行やデータ外部送信（=IMPORTXML 等）につながるため、以下で緩和する。
 *
 * - escape()  … 危険文字で始まるセルの先頭に `'`（アポストロフィ）を付与する。
 *               `'` は表計算アプリ共通で「文字列として扱う」記法。
 * - restore() … 「先頭が `'` かつ直後の文字が危険文字」のときのみ `'` を1つ除去して復元する。
 *               それ以外の先頭 `'`（例 `'メモ`）は人が意図した文字として保持する。
 *
 * これにより「機械が付けた `'`」と「人が入れた `'`」を区別し、往復（export→import）の整合を保つ。
 * 残余エッジ：ユーザーが意図的に `'=x`（アポストロフィ＋危険文字）を入力していた場合のみ、
 * 取込時に `=x` へ変化する（頻度極小・実害軽微のため許容）。
 *
 * 設計原則：SRP（インジェクション対策の1責務に限定）／DRY（export/import 双方から共有）。
 */
class CsvInjection
{
    /**
     * セル先頭がこれらの文字の場合に数式として解釈されうる（表計算アプリ共通）。
     * `=` `+` `-` `@`、タブ（0x09）、CR（0x0D）。
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * エクスポート用エスケープ：危険文字で始まる値の先頭に `'` を付与する。
     * null は空文字と同義（呼び出し側で '' に正規化される想定）。
     */
    public static function escape(?string $value): string
    {
        if ($value === null || $value === '') {
            return (string) $value;
        }

        if (self::startsWithDangerousChar($value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * インポート用復元（条件付き）：
     * 先頭が `'` で、かつその直後の文字が危険文字のときのみ、先頭 `'` を1つ除去する。
     */
    public static function restore(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // 先頭が `'` かつ2文字目以降が存在し、それが危険文字のときだけ復元する。
        if ($value[0] === "'" && strlen($value) >= 2 && self::startsWithDangerousChar(substr($value, 1))) {
            return substr($value, 1);
        }

        return $value;
    }

    /**
     * 値の先頭が危険文字か。
     */
    private static function startsWithDangerousChar(string $value): bool
    {
        return in_array($value[0], self::DANGEROUS_PREFIXES, true);
    }
}
