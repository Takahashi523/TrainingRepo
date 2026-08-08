<?php

namespace App\Services\Csv;

use App\Support\Csv\CsvInjection;
use App\Support\Csv\CsvSchema;
use Illuminate\Database\Eloquent\Collection;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV エクスポートの中核。絞り込み済みクエリ（SELECT 明示・担当者名 Eager Loading）を
 * league/csv Writer で BOM 付き・RFC4180 準拠に整形し、StreamedResponse で返す。
 *
 * - 各セルは CsvInjection::escape を通す（数式インジェクション対策・O-4）。
 * - 0件でもヘッダー行のみを返す。
 * - ダウンロードファイル名はサーバー生成（[A-Za-z0-9_]+.csv・O-12）。ユーザー入力を含めない。
 *
 * 設計原則：SRP（エクスポートの1責務）／DRY（列定義・整形は CsvSchema に委譲）。
 */
class CsvExportService
{
    public function stream(CsvSchema $schema, array $filters): StreamedResponse
    {
        // ファイル名は完全サーバー生成。文字集合を [A-Za-z0-9_] ＋ .csv に限定しヘッダー/CRLF 注入を封じる。
        $safeResource = preg_replace('/[^A-Za-z0-9_]/', '_', $schema->resourceKey());
        $filename = $safeResource.'_'.now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return new StreamedResponse(function () use ($schema, $filters): void {
            $out = fopen('php://output', 'w');

            // ストリーム書き込み（insertOne）では league が BOM を自動付与しないため、先頭に手動出力する。
            // Excel 等の文字化け防止（UTF-8 BOM）。
            fwrite($out, Writer::BOM_UTF8);

            $writer = Writer::createFromStream($out);
            $writer->setEscape('');         // RFC4180：バックスラッシュ escape を無効化
            $writer->setEndOfLine("\r\n");  // RFC4180 は CRLF

            // ヘッダー行（0件でも出力する）
            $writer->insertOne(array_map([CsvInjection::class, 'escape'], $schema->exportHeaders()));

            $schema->exportQuery($filters)->chunk(500, function (Collection $models) use ($writer, $schema): void {
                foreach ($models as $model) {
                    $writer->insertOne(array_map([CsvInjection::class, 'escape'], $schema->exportRow($model)));
                }
            });
        }, 200, $headers);
    }
}
