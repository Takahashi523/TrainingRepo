<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormFieldSetting extends Model
{
    protected $fillable = ['form_type', 'field_key', 'is_required', 'is_system_required', 'updated_by'];

    /**
     * field_key → 表示名の SSOT（表示順も兼ねる）。
     * 表示名は実登録フォーム（EngineerForm / ProjectForm）と一致させること。
     * 案件側の並び順は暫定（最終的な順序整合は issue #43 に委譲）。
     *
     * @var array<string, array<string, string>>
     */
    public const FIELD_LABELS = [
        'engineer' => [
            'name' => '氏名',
            'name_kana' => '氏名カナ',
            'birth_date' => '生年月日',
            'nearest_station' => '最寄駅',
            'nearest_line' => '路線',
            'available_from' => '稼働可能時期',
            'skills' => '経験スキル',
            'proc_experience' => '経験工程',
            'has_negotiation_exp' => '顧客折衝経験',
            'appeal_note' => 'アピールポイント',
            'desired_rate' => '希望単価（月額）',
            'work_styles' => '勤務形態',
            'remarks' => '特記事項',
            'status' => 'ステータス',
            'main_user_id' => '担当営業',
        ],
        'project' => [
            'name' => '案件名',
            'client_name' => '顧客名',
            'required_skills' => '必須スキル',
            'preferred_skills' => '尚可スキル',
            'rate' => '単価（月額）',
            'start_date' => '参画開始時期',
            'work_style' => '稼働形態',
            'work_location' => '勤務地',
            'commercial_flow' => '商流',
            'interview_count' => '面談回数',
            'headcount' => '募集人数',
            'work_env' => '稼働環境',
            'billing_range' => '精算幅',
            'proc_experience' => '対象工程',
            'negotiation_required' => '顧客折衝経験',
            'description' => '業務内容詳細',
            'remarks' => '特記事項',
            'status' => 'ステータス',
            'main_user_id' => '担当営業',
        ],
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_system_required' => 'boolean',
        ];
    }

    /**
     * 表示名を返す（未定義キーは field_key をそのまま返す）。
     */
    public function fieldLabel(): string
    {
        return self::FIELD_LABELS[$this->form_type][$this->field_key] ?? $this->field_key;
    }

    /**
     * FIELD_LABELS 上の表示順インデックス（未定義は末尾）。
     */
    public function displayOrder(): int
    {
        $keys = array_keys(self::FIELD_LABELS[$this->form_type] ?? []);
        $index = array_search($this->field_key, $keys, true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
