<?php

namespace App\Http\Requests;

use App\Models\FormFieldSetting;
use App\Validation\ProjectRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // TODO: 認証済みユーザーであることは auth ミドルウェアで担保。
        // ロール制御が必要な場合はここに追加する。
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 書式・範囲ルールは共有の単一出所（ProjectRules）から取得し、
        // 必須/任意・条件付きルール（相互必須・required_if 等）はここで組み立てる（DRY）。
        $shared = ProjectRules::formatRules();

        $settings = FormFieldSetting::where('form_type', 'project')
            ->pluck('is_required', 'field_key');

        $isRequired = fn (string $key): bool => (bool) $settings->get($key, false);

        // 第1層：システム固定必須
        $rules = [
            'name' => ['required', ...$shared['name']],
            'status' => ['required', ...$shared['status']],
            'main_user_id' => ['required', ...$shared['main_user_id']],
        ];

        // 第2層：動的フィールド（form_field_settings で必須/任意を制御）
        $dynamicFields = [
            'client_name', 'headcount', 'start_date', 'commercial_flow', 'work_style',
            'interview_count', 'negotiation_required', 'description', 'work_env',
            'billing_range', 'remarks',
        ];

        foreach ($dynamicFields as $field) {
            $rules[$field] = [$isRequired($field) ? 'required' : 'nullable', ...$shared[$field]];
        }

        // ----------------------------------------------------------------
        // 単価：rate フィールドキー1つで rate_min / rate_max / rate_note をまとめて制御する
        // rate_is_negotiable が true の場合は rate_min / rate_max をバリデーション対象外とする
        // ----------------------------------------------------------------
        $rules['rate_is_negotiable'] = ['nullable', ...$shared['rate_is_negotiable']];
        $rules['rate_note'] = ['nullable', ...$shared['rate_note']];

        $rateRequired = $isRequired('rate');

        if ($this->boolean('rate_is_negotiable')) {
            // スキル見合いの場合は rate_min / rate_max を完全に nullable にする
            $rules['rate_min'] = ['nullable', ...$shared['rate_min']];
            $rules['rate_max'] = ['nullable', ...$shared['rate_max']];
        } else {
            // 通常の場合：下限・上限の相互必須チェック
            $rateMinRules = [$rateRequired ? 'required' : 'nullable', ...$shared['rate_min']];
            $rateMaxRules = [$rateRequired ? 'required' : 'nullable', ...$shared['rate_max']];

            if ($this->filled('rate_min')) {
                $rateMaxRules[] = 'required';
                $rateMaxRules[] = 'gte:rate_min';
            }
            if ($this->filled('rate_max')) {
                $rateMinRules[] = 'required';
                $rateMinRules[] = 'lte:rate_max';
            }

            $rules['rate_min'] = $rateMinRules;
            $rules['rate_max'] = $rateMaxRules;
        }

        // ----------------------------------------------------------------
        // 勤務地：work_location フィールドキー1つで line / station をまとめて制御する
        // work_location_station の条件付き必須は業務ルール固定（form_field_settings 対象外）
        //   - work_style が onsite / hybrid の場合 → 必須
        //   - work_style が remote の場合         → バリデーション対象外
        // ----------------------------------------------------------------
        $workLocationRequired = $isRequired('work_location');
        $workStyle = $this->input('work_style');
        $isWorkLocationActive = in_array($workStyle, ['onsite', 'hybrid'], true);

        $rules['work_location_line'] = [
            ($workLocationRequired && $isWorkLocationActive) ? 'required' : 'nullable',
            ...$shared['work_location_line'],
        ];

        $rules['work_location_station'] = [
            'nullable', 'required_if:work_style,onsite,hybrid', ...$shared['work_location_station'],
        ];

        // ----------------------------------------------------------------
        // スキル：required_skills / preferred_skills フィールドキーで個別制御
        // ----------------------------------------------------------------
        $rules['required_skills'] = [$isRequired('required_skills') ? 'required' : 'nullable', 'array'];
        $rules['preferred_skills'] = [$isRequired('preferred_skills') ? 'required' : 'nullable', 'array'];

        // detail が入力されている行はlabelも必須にする（required_withはワイルドカードの
        // 相互参照に対応している。EngineerRequest::rules()のskills.*.labelと同様の書き方）
        $rules['required_skills.*.label'] = [
            $isRequired('required_skills') ? 'required' : 'required_with:required_skills.*.detail',
            ...$shared['required_skills.*.label'],
        ];
        $rules['required_skills.*.detail'] = ['nullable', ...$shared['required_skills.*.detail']];

        $rules['preferred_skills.*.label'] = [
            $isRequired('preferred_skills') ? 'required' : 'required_with:preferred_skills.*.detail',
            ...$shared['preferred_skills.*.label'],
        ];
        $rules['preferred_skills.*.detail'] = ['nullable', ...$shared['preferred_skills.*.detail']];

        // ----------------------------------------------------------------
        // 対象工程：proc_experience の1設定で proc_* 6フィールドをまとめて制御する
        // WF_12のフォーム設定タブでも「対象工程」として1つのトグルで管理する
        // ----------------------------------------------------------------
        $procRequired = $isRequired('proc_experience');
        foreach (['proc_requirements', 'proc_basic_design', 'proc_detail_design',
            'proc_development', 'proc_testing', 'proc_maintenance'] as $field) {
            $rules[$field] = [$procRequired ? 'required' : 'nullable', ...$shared[$field]];
        }

        // ----------------------------------------------------------------
        // 管理情報（固定）
        // ----------------------------------------------------------------
        $rules['sub_user_id'] = ['nullable', ...$shared['sub_user_id']];

        if ($this->isMethod('put')) {
            $rules['version'] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'name' => '案件名',
            'status' => 'ステータス',
            'main_user_id' => '主担当営業',
            'sub_user_id' => 'サブ担当営業',
            'client_name' => '顧客名',
            'headcount' => '募集人数',
            'start_date' => '参画開始時期',
            'rate_is_negotiable' => 'スキル見合いフラグ',
            'rate_min' => '単価下限',
            'rate_max' => '単価上限',
            'rate_note' => '単価備考',
            'commercial_flow' => '商流',
            'work_style' => '稼働形態',
            'work_location_line' => '路線名',
            'work_location_station' => '最寄駅',
            'interview_count' => '面談回数',
            'negotiation_required' => '顧客折衝経験要否',
            'description' => '業務内容詳細',
            'work_env' => '稼働環境',
            'billing_range' => '精算幅',
            'remarks' => '特記事項',
            'required_skills' => '必須スキル',
            'required_skills.*.label' => '必須スキル名',
            'required_skills.*.detail' => '必須スキル詳細',
            'preferred_skills' => '尚可スキル',
            'preferred_skills.*.label' => '尚可スキル名',
            'preferred_skills.*.detail' => '尚可スキル詳細',
            'proc_requirements' => '要件定義',
            'proc_basic_design' => '基本設計',
            'proc_detail_design' => '詳細設計',
            'proc_development' => '開発',
            'proc_testing' => 'テスト',
            'proc_maintenance' => '保守運用',
            'version' => 'バージョン',
        ];
    }
}
