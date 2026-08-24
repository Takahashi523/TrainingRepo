<?php

namespace App\Support\Csv;

use App\Models\Project;
use App\Validation\ProjectRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * 案件（projects）CSV のカラム定義（api/08 §3：A〜AC の29列）。
 *
 * 列順・ヘッダー名は api/08 §3 を正とする。担当者名（主担当名/サブ担当名）は
 * エクスポート専用（export_only）でインポート時は無視する（案件に ai_summary 列は無い）。
 */
class ProjectCsvSchema extends CsvSchema
{
    public function columns(): array
    {
        return [
            ['header' => 'id', 'field' => 'id', 'type' => 'id'],
            ['header' => '案件名', 'field' => 'name', 'type' => 'string'],
            ['header' => '顧客名', 'field' => 'client_name', 'type' => 'string'],
            ['header' => '募集人数', 'field' => 'headcount', 'type' => 'integer'],
            ['header' => '参画開始時期', 'field' => 'start_date', 'type' => 'date'],
            ['header' => '単価下限（万円）', 'field' => 'rate_min', 'type' => 'integer'],
            ['header' => '単価上限（万円）', 'field' => 'rate_max', 'type' => 'integer'],
            ['header' => '単価備考', 'field' => 'rate_note', 'type' => 'string'],
            ['header' => '商流', 'field' => 'commercial_flow', 'type' => 'enum'],
            ['header' => '稼働形態', 'field' => 'work_style', 'type' => 'enum'],
            ['header' => '勤務地（路線名）', 'field' => 'work_location_line', 'type' => 'string'],
            ['header' => '勤務地（最寄駅）', 'field' => 'work_location_station', 'type' => 'string'],
            ['header' => '面談回数', 'field' => 'interview_count', 'type' => 'integer'],
            ['header' => '顧客折衝経験要否', 'field' => 'negotiation_required', 'type' => 'flag'],
            ['header' => '業務内容詳細', 'field' => 'description', 'type' => 'text'],
            ['header' => '稼働環境', 'field' => 'work_env', 'type' => 'text'],
            ['header' => '精算幅', 'field' => 'billing_range', 'type' => 'string'],
            ['header' => '特記事項', 'field' => 'remarks', 'type' => 'text'],
            ['header' => 'ステータス', 'field' => 'status', 'type' => 'enum'],
            ['header' => '主担当ID', 'field' => 'main_user_id', 'type' => 'user'],
            ['header' => '主担当名', 'field' => null, 'type' => 'relation_name', 'relation' => 'mainUser', 'export_only' => true],
            ['header' => 'サブ担当ID', 'field' => 'sub_user_id', 'type' => 'user'],
            ['header' => 'サブ担当名', 'field' => null, 'type' => 'relation_name', 'relation' => 'subUser', 'export_only' => true],
            ['header' => '要件定義対象', 'field' => 'proc_requirements', 'type' => 'flag'],
            ['header' => '基本設計対象', 'field' => 'proc_basic_design', 'type' => 'flag'],
            ['header' => '詳細設計対象', 'field' => 'proc_detail_design', 'type' => 'flag'],
            ['header' => '開発対象', 'field' => 'proc_development', 'type' => 'flag'],
            ['header' => 'テスト対象', 'field' => 'proc_testing', 'type' => 'flag'],
            ['header' => '保守運用対象', 'field' => 'proc_maintenance', 'type' => 'flag'],
        ];
    }

    protected function sharedFormatRules(): array
    {
        return ProjectRules::formatRules();
    }

    public function requiredFields(): array
    {
        return ['name', 'status', 'main_user_id'];
    }

    public function modelClass(): string
    {
        return Project::class;
    }

    public function resourceKey(): string
    {
        return 'projects';
    }

    /**
     * 案件固有の条件付きルール：
     *   - 最寄駅は稼働形態が onsite/hybrid の行で必須（業務ルール固定・form_field_settings 対象外）
     *   - 単価は下限・上限が両方入力されている行でのみ相互 lte/gte を課す（フォームと同じ挙動）
     *
     * @param  array<string, mixed>  $row
     * @return array<string, array<int, mixed>>
     */
    protected function conditionalImportRules(array $row): array
    {
        $extra = [
            'work_location_station' => ['required_if:work_style,onsite,hybrid'],
        ];

        $rateMinFilled = isset($row['rate_min']) && $row['rate_min'] !== null && $row['rate_min'] !== '';
        $rateMaxFilled = isset($row['rate_max']) && $row['rate_max'] !== null && $row['rate_max'] !== '';

        if ($rateMinFilled && $rateMaxFilled) {
            $extra['rate_min'] = ['lte:rate_max'];
            $extra['rate_max'] = ['gte:rate_min'];
        }

        return $extra;
    }

    public function exportQuery(array $filters): Builder
    {
        $query = Project::query()
            ->select([
                'id', 'name', 'client_name', 'headcount', 'start_date', 'rate_min', 'rate_max',
                'rate_note', 'commercial_flow', 'work_style', 'work_location_line', 'work_location_station',
                'interview_count', 'negotiation_required', 'description', 'work_env', 'billing_range',
                'remarks', 'status', 'main_user_id', 'sub_user_id',
                'proc_requirements', 'proc_basic_design', 'proc_detail_design',
                'proc_development', 'proc_testing', 'proc_maintenance',
            ])
            ->with(['mainUser:id,name', 'subUser:id,name'])
            ->orderBy('id');

        if (! empty($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }

        if (! empty($filters['user_id'])) {
            $userId = (int) $filters['user_id'];
            $query->where(fn ($q) => $q->where('main_user_id', $userId)->orWhere('sub_user_id', $userId));
        }

        if (! empty($filters['start_date_from'])) {
            $query->whereDate('start_date', '>=', $filters['start_date_from']);
        }
        if (! empty($filters['start_date_to'])) {
            $query->whereDate('start_date', '<=', $filters['start_date_to']);
        }

        if (! empty($filters['work_style'])) {
            $styles = array_intersect((array) $filters['work_style'], ['onsite', 'hybrid', 'remote']);
            if ($styles) {
                $query->whereIn('work_style', $styles);
            }
        }

        if (! empty($filters['keyword'])) {
            $prefix = $this->escapeLike((string) $filters['keyword']).'%'; // 必須スキルの前方一致
            $query->whereHas('projectSkills', fn ($s) => $s->where('label', 'like', $prefix));
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
