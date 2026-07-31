<?php

namespace App\Http\Requests\Csv;

use App\Support\Csv\CsvFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * CSV インポートのファイルレベルバリデーション（人材・案件共通）。
 *
 * ここでは「ファイルとして受け付けられるか」だけを検証する（拡張子・サイズ・文字コード・行数上限）。
 * ヘッダー照合や行ごとの項目バリデーションは動的・行単位のため CsvImportService の責務とする
 * （そちらは errors.importErrors の構造化エラーで返す）。
 *
 * チェック順序（api/08 確定）：①ファイル（ここ）→ ②ヘッダー → ③レイアウト → ④項目。
 */
class CsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 認証は auth ミドルウェア、CSV アクセス権は CsvController の Gate（access-csv）で担保する。
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // mimes:csv,txt … text/csv が txt と判定される環境があるため両方許容（拡張子は下の after() で厳格化）
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'CSVファイルを選択してください。',
            'file.file' => 'CSVファイルを選択してください。',
            'file.mimes' => '.csvファイルを選択してください。',
            'file.max' => 'ファイルサイズは5MB以内にしてください。',
        ];
    }

    /**
     * 拡張子（.csv）・文字コード（UTF-8）・データ行数上限（5,000）を追加検証する。
     * これらは Laravel 標準ルールでは表現しづらいため after フックで実装する。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // 前段（required/file/mimes/max）で既に失敗している場合は、ファイル本体を触らない
            if ($validator->errors()->has('file')) {
                return;
            }

            $file = $this->file('file');

            // 実拡張子の厳格チェック（クライアント指定の拡張子は信用しつつ .csv のみ許可）
            if (strtolower((string) $file->getClientOriginalExtension()) !== 'csv') {
                $validator->errors()->add('file', '.csvファイルを選択してください。');

                return;
            }

            $content = (string) file_get_contents($file->getRealPath());

            // BOM を除いた本文が UTF-8 か（BOM 有無どちらも UTF-8 として通す・O-6）
            if (! mb_check_encoding(CsvFile::stripBom($content), 'UTF-8')) {
                $validator->errors()->add('file', 'UTF-8形式のCSVファイルをアップロードしてください。');

                return;
            }

            // データ行数の上限（O-13）。空行スキップ後のデータ行が 5,000 を超えるとエラー
            if (CsvFile::countDataRows($content) > 5000) {
                $validator->errors()->add('file', '一度に取り込めるのは5,000行までです。ファイルを分割して取り込んでください。');
            }
        });
    }
}
