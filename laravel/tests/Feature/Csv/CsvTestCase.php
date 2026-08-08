<?php

namespace Tests\Feature\Csv;

use App\Models\User;
use App\Support\Csv\CsvSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use League\Csv\Writer;
use Tests\TestCase;

/**
 * CSV 入出力 Feature テストの共通土台（ユーザー生成・CSV 生成・アップロード・レスポンス整形）。
 */
abstract class CsvTestCase extends TestCase
{
    protected function makeUser(string $role = 'general'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * スキーマの「インポート対象ヘッダー（export-only 列を除く）」を用いて CSV 文字列を生成する。
     * $rows は field => value の連想配列の配列。未指定 field は空セルになる。
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function buildCsv(CsvSchema $schema, array $rows): string
    {
        $map = $schema->importableHeaderMap(); // header => field
        $headers = array_keys($map);
        $fields = array_values($map);

        $writer = Writer::createFromString();
        $writer->setEscape('');
        $writer->insertOne($headers);
        foreach ($rows as $row) {
            $writer->insertOne(array_map(fn ($f) => (string) ($row[$f] ?? ''), $fields));
        }

        return $writer->toString();
    }

    /**
     * テスト用の UploadedFile を実ファイルとして生成する（test mode）。
     */
    protected function makeUpload(string $content, string $name = 'data.csv', string $mime = 'text/csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csvtest');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    /**
     * インポート POST（既定は Accept: application/json でエラー時 422 を受け取る）。
     */
    protected function postImport(User $user, string $routeName, UploadedFile $file, bool $expectJson = true): TestResponse
    {
        $headers = $expectJson ? ['Accept' => 'application/json'] : [];

        return $this->actingAs($user)->post(route($routeName), ['file' => $file], $headers);
    }

    /**
     * 422 レスポンスから importErrors（構造化配列）を取り出す。
     *
     * @return array<int, array{row: ?int, field: ?string, messages: array<int, string>}>
     */
    protected function importErrors(TestResponse $response): array
    {
        $json = $response->json('errors.importErrors.0');

        return json_decode((string) $json, true) ?? [];
    }
}
