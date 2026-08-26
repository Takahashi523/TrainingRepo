<?php

namespace Tests\Unit;

use App\Models\Project;
use PHPUnit\Framework\TestCase;

/**
 * Project::startLabel（参画開始時期の表示ラベル）。
 *
 * 一覧・詳細・マッチングの3リソースに同一ロジックがコピペされていたものをモデルへ集約したため（#57）、
 * 生成規則そのものはここで固定する。DB を使わないためモデル単体で検証する。
 */
class ProjectStartLabelTest extends TestCase
{
    public function test_returns_slashed_date_with_tilde_when_start_date_is_present(): void
    {
        $project = new Project(['start_date' => '2026-08-01']);

        $this->assertSame('2026/08/01〜', $project->start_label);
    }

    public function test_pads_month_and_day_with_zero(): void
    {
        // 1桁の月日でもゼロ埋めされること（人材の available_label と桁揃えするため）。
        $project = new Project(['start_date' => '2026-01-05']);

        $this->assertSame('2026/01/05〜', $project->start_label);
    }

    public function test_returns_undecided_label_when_start_date_is_null(): void
    {
        // null は null のまま返さず「未定」を返す契約（フロントは常に文字列として扱える）。
        $project = new Project(['start_date' => null]);

        $this->assertSame('未定', $project->start_label);
    }

    public function test_accepts_datetime_string_and_drops_time_part(): void
    {
        // DB から日時付き文字列で読み戻った場合でも日付までを表示する。
        $project = new Project(['start_date' => '2026-12-31 09:30:00']);

        $this->assertSame('2026/12/31〜', $project->start_label);
    }
}
