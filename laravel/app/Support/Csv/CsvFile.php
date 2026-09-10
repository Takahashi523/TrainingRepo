<?php

namespace App\Support\Csv;

use League\Csv\Reader;

/**
 * CSV バイト列の読み取りに関する低レベルユーティリティ（SSOT）。
 *
 * BOM ストリップ（O-6）・RFC4180 準拠パース・論理レコード単位の行番号維持を
 * 1箇所に集約し、CsvImportRequest（行数上限の事前判定）と CsvImportService（本処理）が
 * 同一の読み取り規則を共有できるようにする（DRY）。
 *
 * 設計原則：DRY／SRP（「CSV バイト列をどう論理レコードへ分解するか」に責務を限定）。
 */
class CsvFile
{
    /** UTF-8 BOM（\xEF\xBB\xBF）。 */
    private const BOM_UTF8 = "\xEF\xBB\xBF";

    /**
     * 一度に取り込めるデータ行数の上限（O-13）。ヘッダーを除いた論理データ行数で数える。
     * 事前判定（CsvImportRequest）と本処理の保険（CsvImportService）の双方がこの値を共有する（SSOT）。
     */
    public const MAX_DATA_ROWS = 5000;

    /**
     * 先頭の UTF-8 BOM を取り除く（O-6）。
     * BOM 付きでエクスポートした CSV を再インポートしたとき、先頭ヘッダーが "﻿id" になり
     * ヘッダー照合が落ちるのを防ぐ。
     */
    public static function stripBom(string $content): string
    {
        if (str_starts_with($content, self::BOM_UTF8)) {
            return substr($content, strlen(self::BOM_UTF8));
        }

        return $content;
    }

    /**
     * RFC4180 準拠で論理レコードへ分解し、[オフセット => セル配列] を返す。
     *
     * - オフセット 0 = ヘッダー行（論理レコード0）。データ行は 1 以降。
     * - 引用符（"）内の改行は1論理レコードの一部として扱う（\n 単純分割はしない）。
     * - "改行だけの空行" はスキップされ、オフセット（=論理行番号）は物理位置を保つため
     *   スキップしても後続行の行番号は繰り上がらない（Excel の行番号と一致する）。
     * - `setEscape('')` により RFC4180 の "" エスケープのみを解釈する（バックスラッシュ escape を無効化）。
     *
     * @return array<int, array<int, string|null>> オフセット（0起点・論理行）=> セル配列
     */
    public static function readRecords(string $content): array
    {
        $reader = self::makeReader($content);

        // getRecords() の Iterator キーは論理レコードのオフセットを保持する（空行はスキップされ欠番になる）。
        return iterator_to_array($reader->getRecords(), true);
    }

    /**
     * 空行（改行だけの行）をスキップした「データ行数」を数える（ヘッダー除く）。
     * 行数上限（O-13：5,000 行）の事前判定に用いる。
     */
    public static function countDataRows(string $content): int
    {
        $records = self::readRecords($content);

        // オフセット 0（ヘッダー）を除いた件数。空行は league が既にスキップ済み。
        $count = count($records);

        return $count > 0 ? $count - 1 : 0;
    }

    private static function makeReader(string $content): Reader
    {
        $reader = Reader::createFromString(self::stripBom($content));
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setEscape(''); // RFC4180：バックスラッシュ escape を無効化し、"" のみをエスケープとして扱う

        return $reader;
    }
}
