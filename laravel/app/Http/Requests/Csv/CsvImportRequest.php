<?php

namespace App\Http\Requests\Csv;

use App\Support\Csv\CsvFile;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
    /**
     * アップロード上限（KB）。Laravel の file `max` ルールの単位に合わせて KB で保持する。
     * フロントのサイズ事前ガード（fail-fast）もこの値を props 経由で受け取り、マジックナンバーを二重管理しない（SSOT）。
     */
    public const MAX_FILE_SIZE_KB = 5120; // = 5MB

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
            // 評価順は配列順（bail で最初の失敗で打ち切り）：required → file → 拡張子 → 形式(mimes) → サイズ。
            // 「まず .csv という名前か」を先に見て、拡張子が合っていて中身だけ偽物のケースを mimes で弾く。
            // mimes:csv,txt … text/csv が txt と判定される環境があるため両方許容（拡張子は下のクロージャで厳格化）。
            'file' => [
                'bail',
                'required',
                'file',
                $this->extensionRule(),
                'mimes:csv,txt',
                'max:'.self::MAX_FILE_SIZE_KB,
            ],
        ];
    }

    /**
     * 実拡張子（.csv）の厳格チェック。クライアント指定の拡張子を信用しつつ .csv のみ許可する。
     * 形式(mimes)より前に評価し、「拡張子が違う」ケースを「形式が違う」ケースと切り分ける。
     */
    private function extensionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            // required/file を通過していれば UploadedFile。想定外は前段ルールに委ねる。
            if (! $value instanceof UploadedFile) {
                return;
            }

            if (strtolower((string) $value->getClientOriginalExtension()) !== 'csv') {
                $fail('ファイルの拡張子を「.csv」にしてください。');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'CSVファイルを選択してください。',
            'file.file' => 'CSVファイルを選択してください。',
            // mimes は拡張子でなくファイル内容（推測MIME）を見ているため「形式」として案内する
            'file.mimes' => 'ファイル形式が正しくありません。CSVファイル（.csv）をアップロードしてください。',
            'file.max' => 'ファイルサイズは'.(self::MAX_FILE_SIZE_KB / 1024).'MB以内にしてください。',
            // PHP の upload/post 上限超過などで発火する uploaded ルール。生の項目名「file」を出さず、サイズ起因と分かる文言にする。
            'file.uploaded' => 'ファイルのアップロードに失敗しました。ファイルサイズが大きすぎる可能性があります（'.(self::MAX_FILE_SIZE_KB / 1024).'MB以内）。',
        ];
    }

    /**
     * 文字コード（UTF-8）・データ行数上限（5,000）を追加検証する。
     * これらは Laravel 標準ルールでは表現しづらいため after フックで実装する
     * （拡張子・形式・サイズは rules() 側で bail 付きで検証済み）。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // 前段（required/file/拡張子/mimes/max）で既に失敗している場合は、ファイル本体を触らない
            if ($validator->errors()->has('file')) {
                return;
            }

            $file = $this->file('file');

            $content = (string) file_get_contents($file->getRealPath());

            // BOM を除いた本文が UTF-8 か（BOM 有無どちらも UTF-8 として通す・O-6）
            if (! mb_check_encoding(CsvFile::stripBom($content), 'UTF-8')) {
                $validator->errors()->add('file', 'UTF-8形式のCSVファイルをアップロードしてください。');

                return;
            }

            // データ行数の上限（O-13）。空行スキップ後のデータ行が上限を超えるとエラー（閾値は CsvFile に集約）
            if (CsvFile::countDataRows($content) > CsvFile::MAX_DATA_ROWS) {
                $validator->errors()->add('file', '一度にインポートできるのは'.number_format(CsvFile::MAX_DATA_ROWS).'行までです。ファイルを分割してインポートし直してください。');
            }
        });
    }
}
