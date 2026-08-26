<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormFieldSetting extends Model
{
    protected $fillable = ['form_type', 'field_key', 'is_required', 'is_system_required', 'updated_by'];

    /**
     * field_key → 表示名の SSOT（表示順も兼ねる）。
     * 表示名は実登録フォーム（EngineerForm / ProjectForm）と一致させること。
     * 並び順もフォームのセクション・項目順と一致させること（マスタ管理の一覧は
     * MasterController が displayOrder() でこの定義順に並べ替えて表示するため、
     * ここがずれると設定したい項目をフォームと同じ順で辿れなくなる）。
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
        // ProjectForm.tsx のセクション・項目順と 1 対 1 で対応させる（issue #43）。
        'project' => [
            // 基本情報
            'name' => '案件名',
            'client_name' => '顧客名',
            'headcount' => '募集人数',
            'interview_count' => '面談回数',
            'start_date' => '参画開始時期',
            // 契約条件
            'rate' => '単価（月額）',
            'billing_range' => '精算幅',
            'commercial_flow' => '商流',
            // 勤務条件（work_location が制御するのは路線名だけ。最寄駅は業務ルールで固定必須）
            'work_style' => '稼働形態',
            'work_location' => '勤務地（路線名）',
            'remarks' => '特記事項',
            // スキル要件
            'required_skills' => '必須スキル',
            'preferred_skills' => '尚可スキル',
            'proc_experience' => '対象工程',
            'negotiation_required' => '顧客折衝経験',
            'description' => '業務内容詳細',
            'work_env' => '稼働環境',
            // 管理情報
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
