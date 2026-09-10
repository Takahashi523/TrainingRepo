<?php

namespace App\Validation;

use App\Models\Engineer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 人材（engineer）フィールドの「書式・範囲ルール」の単一出所（SSOT）。
 *
 * ここでは max / regex / date / in / different などの「書式・範囲」ルールのみを提供し、
 * required / nullable（必須・任意）は含めない。必須判定は呼び出し側の責務とする：
 *   - EngineerRequest … form_field_settings に応じて required/nullable を前置き
 *   - CsvImportService（フェーズ2） … CSV 固有の必須集合を前置き＋独自の厳格化
 *     （date_format:Y-m-d / in:0,1 等）を追加する
 *
 * enum の許容値は Model 定数（Engineer::STATUSES / Engineer::WORK_STYLES）から取得し、
 * ステータス・勤務形態コードの二重管理を避ける（DRY）。
 *
 * 設計原則：DRY（単一情報源）／SRP（バリデーションの書式ルールに責務を限定）。
 */
class EngineerRules
{
    /**
     * フィールドキー => 書式・範囲ルール配列（required/nullable を含まない）。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function formatRules(): array
    {
        return [
            'name' => ['string', 'max:100'],
            'name_kana' => ['string', 'max:100', 'regex:/^[ァ-ヶー　]+$/u'],
            'status' => [self::statusRule()],
            'main_user_id' => ['integer', 'exists:users,id'],
            'sub_user_id' => ['integer', 'exists:users,id', 'different:main_user_id'],
            'birth_date' => ['date', 'before_or_equal:today'],
            'nearest_station' => ['string', 'max:100'],
            'nearest_line' => ['string', 'max:100'],
            'available_from' => ['date'],
            'has_negotiation_exp' => ['boolean'],
            'appeal_note' => ['string', 'max:4000'],
            'desired_rate' => ['integer', 'min:0', 'max:999'],
            'remarks' => ['string', 'max:1000'],
            'work_styles.*' => [self::workStyleRule()],
            'skills.*.label' => ['string', 'max:15'],
            'skills.*.detail' => ['string', 'max:500'],
            'proc_requirements' => ['boolean'],
            'proc_basic_design' => ['boolean'],
            'proc_detail_design' => ['boolean'],
            'proc_development' => ['boolean'],
            'proc_testing' => ['boolean'],
            'proc_maintenance' => ['boolean'],
        ];
    }

    /**
     * 許容ステータス値（Engineer::STATUSES 由来）。
     *
     * @return array<int, string>
     */
    public static function statusValues(): array
    {
        return array_column(Engineer::STATUSES, 'value');
    }

    /**
     * 許容勤務形態値（Engineer::WORK_STYLES 由来）。
     *
     * @return array<int, string>
     */
    public static function workStyleValues(): array
    {
        return array_column(Engineer::WORK_STYLES, 'key');
    }

    public static function statusRule(): In
    {
        return Rule::in(self::statusValues());
    }

    public static function workStyleRule(): In
    {
        return Rule::in(self::workStyleValues());
    }
}
