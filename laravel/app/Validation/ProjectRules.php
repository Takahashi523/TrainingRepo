<?php

namespace App\Validation;

use App\Models\Project;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * 案件（project）フィールドの「書式・範囲ルール」の単一出所（SSOT）。
 *
 * EngineerRules と同方針：max / date / in / different / min 等の「書式・範囲」ルールのみを提供し、
 * required / nullable や条件付きルール（gte:rate_min / required_if:work_style,... 等）は含めない。
 * それらの必須・相互依存ロジックは呼び出し側（ProjectRequest / CSV）の責務とする。
 *
 * enum の許容値は Model 定数（Project::STATUSES / COMMERCIAL_FLOWS / WORK_STYLES）から取得し、
 * コードの二重管理を避ける（DRY）。
 *
 * 設計原則：DRY（単一情報源）／SRP（書式ルールに責務を限定）。
 */
class ProjectRules
{
    /**
     * フィールドキー => 書式・範囲ルール配列（required/nullable・条件付きルールを含まない）。
     *
     * @return array<string, array<int, mixed>>
     */
    public static function formatRules(): array
    {
        return [
            'name' => ['string', 'max:255'],
            'status' => [self::statusRule()],
            'main_user_id' => ['exists:users,id'],
            'sub_user_id' => ['exists:users,id', 'different:main_user_id'],
            'client_name' => ['string', 'max:100'],
            'headcount' => ['integer', 'min:0', 'max:99'],
            'start_date' => ['date'],
            'commercial_flow' => [self::commercialFlowRule()],
            'work_style' => [self::workStyleRule()],
            'interview_count' => ['integer', 'min:0', 'max:10'],
            'negotiation_required' => ['boolean'],
            'description' => ['string'],
            'work_env' => ['string'],
            'billing_range' => ['string', 'max:100'],
            'remarks' => ['string'],
            'rate_is_negotiable' => ['boolean'],
            'rate_note' => ['string', 'max:100'],
            'rate_min' => ['integer', 'min:0', 'max:999'],
            'rate_max' => ['integer', 'min:0', 'max:999'],
            'work_location_line' => ['string', 'max:100'],
            'work_location_station' => ['string', 'max:100'],
            'required_skills.*.label' => ['string', 'max:15'],
            'required_skills.*.detail' => ['string', 'max:500'],
            'preferred_skills.*.label' => ['string', 'max:15'],
            'preferred_skills.*.detail' => ['string', 'max:500'],
            'proc_requirements' => ['boolean'],
            'proc_basic_design' => ['boolean'],
            'proc_detail_design' => ['boolean'],
            'proc_development' => ['boolean'],
            'proc_testing' => ['boolean'],
            'proc_maintenance' => ['boolean'],
        ];
    }

    /**
     * 許容ステータス値（Project::STATUSES 由来）。
     *
     * @return array<int, string>
     */
    public static function statusValues(): array
    {
        return array_column(Project::STATUSES, 'value');
    }

    /**
     * 許容商流値（Project::COMMERCIAL_FLOWS 由来）。
     *
     * @return array<int, string>
     */
    public static function commercialFlowValues(): array
    {
        return array_column(Project::COMMERCIAL_FLOWS, 'value');
    }

    /**
     * 許容稼働形態値（Project::WORK_STYLES 由来）。
     *
     * @return array<int, string>
     */
    public static function workStyleValues(): array
    {
        return array_column(Project::WORK_STYLES, 'key');
    }

    public static function statusRule(): In
    {
        return Rule::in(self::statusValues());
    }

    public static function commercialFlowRule(): In
    {
        return Rule::in(self::commercialFlowValues());
    }

    public static function workStyleRule(): In
    {
        return Rule::in(self::workStyleValues());
    }
}
