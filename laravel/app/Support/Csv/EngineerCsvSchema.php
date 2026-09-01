<?php

namespace App\Support\Csv;

use App\Models\Engineer;
use App\Validation\EngineerRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * 人材（engineers）CSV のカラム定義（api/08 §3：A〜AA の27列）。
 *
 * 列順・ヘッダー名は api/08 §3 を正とする。担当者名（主担当名/サブ担当名）と AI要約は
 * エクスポート専用（export_only）でインポート時は無視する。バージョン（末尾列）は楽観ロック
 * 制御列（issue #45）：インポート時は更新行の照合にのみ使い、値をそのまま書き込みはしない。
 * ヘッダー名を「バージョン（システム管理）」とし、業務担当者が見て「自分が入力・編集する項目
 * ではない」と分かるようにしている（2026-09-01 追記。列自体を無くすとCSV再取込時に競合を
 * 検知する手段が無くなるため、列は残しつつ名称と案内文で誤解を防ぐ方針とした）。
 */
class EngineerCsvSchema extends CsvSchema
{
    public function columns(): array
    {
        return [
            ['header' => 'id', 'field' => 'id', 'type' => 'id'],
            ['header' => '氏名', 'field' => 'name', 'type' => 'string'],
            ['header' => '氏名カナ', 'field' => 'name_kana', 'type' => 'string'],
            ['header' => '生年月日', 'field' => 'birth_date', 'type' => 'date'],
            ['header' => '最寄駅', 'field' => 'nearest_station', 'type' => 'string'],
            ['header' => '路線', 'field' => 'nearest_line', 'type' => 'string'],
            ['header' => '稼働可能時期', 'field' => 'available_from', 'type' => 'date'],
            ['header' => '希望単価（万円）', 'field' => 'desired_rate', 'type' => 'integer'],
            ['header' => 'アピールポイント', 'field' => 'appeal_note', 'type' => 'text'],
            ['header' => 'AI要約', 'field' => 'ai_summary', 'type' => 'text', 'export_only' => true],
            ['header' => '特記事項', 'field' => 'remarks', 'type' => 'text'],
            ['header' => 'ステータス', 'field' => 'status', 'type' => 'enum'],
            ['header' => '主担当ID', 'field' => 'main_user_id', 'type' => 'user'],
            ['header' => '主担当名', 'field' => null, 'type' => 'relation_name', 'relation' => 'mainUser', 'export_only' => true],
            ['header' => 'サブ担当ID', 'field' => 'sub_user_id', 'type' => 'user'],
            ['header' => 'サブ担当名', 'field' => null, 'type' => 'relation_name', 'relation' => 'subUser', 'export_only' => true],
            ['header' => '常駐可', 'field' => 'work_style_onsite', 'type' => 'flag'],
            ['header' => '一部リモート可', 'field' => 'work_style_hybrid', 'type' => 'flag'],
            ['header' => 'フルリモート希望', 'field' => 'work_style_remote', 'type' => 'flag'],
            ['header' => '要件定義経験', 'field' => 'proc_requirements', 'type' => 'flag'],
            ['header' => '基本設計経験', 'field' => 'proc_basic_design', 'type' => 'flag'],
            ['header' => '詳細設計経験', 'field' => 'proc_detail_design', 'type' => 'flag'],
            ['header' => '開発経験', 'field' => 'proc_development', 'type' => 'flag'],
            ['header' => 'テスト経験', 'field' => 'proc_testing', 'type' => 'flag'],
            ['header' => '保守運用経験', 'field' => 'proc_maintenance', 'type' => 'flag'],
            ['header' => '顧客折衝経験', 'field' => 'has_negotiation_exp', 'type' => 'flag'],
            // 楽観ロック制御列（issue #45）。既存の列順（A〜Z）を崩さないため末尾に追加する。
            ['header' => 'バージョン（システム管理）', 'field' => 'version', 'type' => 'version'],
        ];
    }

    protected function sharedFormatRules(): array
    {
        return EngineerRules::formatRules();
    }

    public function requiredFields(): array
    {
        return ['name', 'name_kana', 'status', 'main_user_id'];
    }

    public function modelClass(): string
    {
        return Engineer::class;
    }

    public function resourceKey(): string
    {
        return 'engineers';
    }

    /**
     * 人材は name / name_kana を半角スペース→全角スペースへ正規化する（フォームと一致・#21-3）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function normalizeRow(array $data): array
    {
        foreach (['name', 'name_kana'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = str_replace(' ', '　', $data[$field]);
            }
        }

        return $data;
    }

    public function exportQuery(array $filters): Builder
    {
        $query = Engineer::query()
            ->select([
                'id', 'name', 'name_kana', 'birth_date', 'nearest_station', 'nearest_line',
                'available_from', 'desired_rate', 'appeal_note', 'ai_summary', 'remarks',
                'status', 'main_user_id', 'sub_user_id',
                'work_style_onsite', 'work_style_hybrid', 'work_style_remote',
                'proc_requirements', 'proc_basic_design', 'proc_detail_design',
                'proc_development', 'proc_testing', 'proc_maintenance', 'has_negotiation_exp',
                'version',
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

        if (! empty($filters['available_from_start'])) {
            $query->whereDate('available_from', '>=', $filters['available_from_start']);
        }
        if (! empty($filters['available_from_end'])) {
            $query->whereDate('available_from', '<=', $filters['available_from_end']);
        }

        if (! empty($filters['work_styles'])) {
            $styles = array_intersect((array) $filters['work_styles'], ['onsite', 'hybrid', 'remote']);
            if ($styles) {
                $query->where(function ($q) use ($styles) {
                    foreach ($styles as $ws) {
                        $q->orWhere("work_style_{$ws}", true);
                    }
                });
            }
        }

        if (! empty($filters['keyword'])) {
            $prefix = $this->escapeLike((string) $filters['keyword']).'%'; // スキルは前方一致
            $query->whereHas('skills', fn ($s) => $s->where('label', 'like', $prefix));
        }

        return $query;
    }

    /**
     * LIKE メタ文字（\ % _）をエスケープする。
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
