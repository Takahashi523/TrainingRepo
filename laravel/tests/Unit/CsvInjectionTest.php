<?php

namespace Tests\Unit;

use App\Support\Csv\CsvInjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV インジェクション対策（O-4）の単体テスト。
 * escape で機械的に付与した `'` と、人が意図して入れた `'` を restore が区別できることを検証する。
 */
class CsvInjectionTest extends TestCase
{
    public static function dangerousProvider(): array
    {
        return [
            'equals' => ['=SUM(A1)'],
            'plus' => ['+1'],
            'minus' => ['-1'],
            'at' => ['@cmd'],
            'tab' => ["\tvalue"],
            'cr' => ["\rvalue"],
        ];
    }

    #[DataProvider('dangerousProvider')]
    public function test_escape_prefixes_dangerous_values_and_restore_recovers_them(string $value): void
    {
        $escaped = CsvInjection::escape($value);

        $this->assertSame("'".$value, $escaped, '危険文字始まりの値には先頭に `\'` が付く');
        $this->assertSame($value, CsvInjection::restore($escaped), 'restore で元値に戻る（往復一致）');
    }

    public function test_escape_leaves_safe_values_untouched(): void
    {
        $this->assertSame('山田太郎', CsvInjection::escape('山田太郎'));
        $this->assertSame('proposable', CsvInjection::escape('proposable'));
        $this->assertSame('', CsvInjection::escape(''));
        $this->assertSame('', CsvInjection::escape(null));
    }

    public function test_restore_keeps_human_entered_leading_quote(): void
    {
        // 先頭が `'` でも直後が危険文字でなければ、人が入れた文字として保持する
        $this->assertSame("'メモ", CsvInjection::restore("'メモ"));
        $this->assertSame("'abc", CsvInjection::restore("'abc"));
    }

    public function test_restore_only_strips_when_next_char_is_dangerous(): void
    {
        $this->assertSame('=1', CsvInjection::restore("'=1"));
        $this->assertSame('@x', CsvInjection::restore("'@x"));
        // 残余エッジ：人が意図的に `'=x` と入力していた場合は取込で `=x` に変化しうる（許容）
        $this->assertSame('=x', CsvInjection::restore("'=x"));
    }

    public function test_restore_passes_through_empty_and_null(): void
    {
        $this->assertSame('', CsvInjection::restore(''));
        $this->assertNull(CsvInjection::restore(null));
    }
}
