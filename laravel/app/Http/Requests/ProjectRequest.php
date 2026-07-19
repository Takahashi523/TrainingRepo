<?php

namespace App\Http\Requests;

use App\Models\FormFieldSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProjectRequest extends FormRequest
{
    private \Illuminate\Support\Collection $fieldSettings;

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
        $this->fieldSettings = FormFieldSetting::where('form_type', 'project')
            ->pluck('is_required', 'field_key');
        
        $isRequired = fn(string $key): bool => (bool) $this->fieldSettings->get($key, false);

        // 第1層：システム固定必須
        $rules = [
            'name'         => ['required', 'string', 'max:255'],
            'status'       => ['required', 'in:open,closed,pending'],
            'main_user_id' => ['required', 'exists:users,id'],
        ];

        // 第2層：動的フィールド
        $dynamicFields = [
            'client_name'          => ['string', 'max:100'],
            'headcount'            => ['integer', 'min:0', 'max:99'],
            'start_date'           => ['date'],
            'commercial_flow'      => ['in:prime,secondary,tertiary,other'],
            'work_style'           => ['in:onsite,hybrid,remote'],
            'interview_count'      => ['integer', 'min:0', 'max:10'],
            'negotiation_required' => ['boolean'],
            'description'          => ['string'],
            'work_env'             => ['string'],
            'billing_range'        => ['string', 'max:100'],
            'remarks'              => ['string'],
        ];

        foreach ($dynamicFields as $field => $fieldRules) {
           $rules[$field] = array_merge(
                [$isRequired($field) ? 'required' : 'nullable'],
                $fieldRules
            );
        }
       
        // ----------------------------------------------------------------
        // 単価：rate フィールドキー1つで rate_min / rate_max / rate_note をまとめて制御する
        // rate_is_negotiable が true の場合は rate_min / rate_max をバリデーション対象外とする
        // ---------------------------------------------------------------- 
        $rules['rate_is_negotiable'] = ['nullable', 'boolean'];
        $rules['rate_note']          = ['nullable', 'string', 'max:100'];

        $rateRequired = $isRequired('rate');

        if ($this->boolean('rate_is_negotiable')) {
            // スキル見合いの場合は rate_min / rate_max を完全に nullable にする
            $rules['rate_min'] = ['nullable', 'integer', 'min:0', 'max:999'];
            $rules['rate_max'] = ['nullable', 'integer', 'min:0', 'max:999'];
        } else {
            // 通常の場合：下限・上限の相互必須チェック
            $rateMinRules = [$rateRequired ? 'required' : 'nullable', 'integer', 'min:0', 'max:999'];
            $rateMaxRules = [$rateRequired ? 'required' : 'nullable', 'integer', 'min:0', 'max:999'];

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
            'string', 'max:100',
        ];

        $rules['work_location_station'] = $isWorkLocationActive
            ? ['required', 'string', 'max:100']
            : ['nullable', 'string', 'max:100'];

        // ----------------------------------------------------------------
        // スキル：required_skills / preferred_skills フィールドキーで個別制御
        // ----------------------------------------------------------------
        $rules['required_skills']  = [$isRequired('required_skills') ? 'required' : 'nullable', 'array'];
        $rules['preferred_skills'] = [$isRequired('preferred_skills') ? 'required' : 'nullable', 'array'];

        if ($isRequired('required_skills')) {
            $rules['required_skills.*.label']   = ['required', 'string', 'max:15'];
        } else {
            $rules['required_skills.*.label']   = ['nullable', 'string', 'max:15'];
        }
        $rules['required_skills.*.detail']  = ['nullable', 'string', 'max:500'];

        if ($isRequired('preferred_skills')) {
            $rules['preferred_skills.*.label']  = ['required', 'string', 'max:15'];
        } else {
            $rules['preferred_skills.*.label']  = ['nullable', 'string', 'max:15'];
        }
        $rules['preferred_skills.*.detail'] = ['nullable', 'string', 'max:500'];

        // ----------------------------------------------------------------
        // 対象工程：proc_experience の1設定で proc_* 6フィールドをまとめて制御する
        // WF_12のフォーム設定タブでも「対象工程」として1つのトグルで管理する
        // ----------------------------------------------------------------
        $procRequired = $isRequired('proc_experience');
        foreach (['proc_requirements', 'proc_basic_design', 'proc_detail_design',
            'proc_development', 'proc_testing', 'proc_maintenance'] as $field) {
            $rules[$field] = [$procRequired ? 'required' : 'nullable', 'boolean'];
        }
 
        // ----------------------------------------------------------------
        // 管理情報（固定）
        // ----------------------------------------------------------------
        $rules['sub_user_id'] = ['nullable', 'exists:users,id', 'different:main_user_id'];

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $isRequired = fn(string $key): bool => (bool) $this->fieldSettings->get($key, false);

            foreach (['required_skills', 'preferred_skills'] as $field) {
                if ($isRequired($field)) {
                    continue; // required のときは rules() で担保されるためスキップ
                }

                $skills = $this->input($field, []);
                foreach ($skills as $index => $skill) {
                    if (!empty($skill['detail']) && empty($skill['label'])) {
                        $validator->errors()->add(
                            "{$field}.{$index}.label",
                            'スキル詳細を入力する場合はスキル名も入力してください。'
                        );
                    }
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name'                     => '案件名',
            'status'                   => 'ステータス',
            'main_user_id'             => '主担当営業',
            'sub_user_id'              => 'サブ担当営業',
            'client_name'              => '顧客名',
            'headcount'                => '募集人数',
            'start_date'               => '参画開始時期',
            'rate_is_negotiable'       => 'スキル見合いフラグ',
            'rate_min'                 => '単価下限',
            'rate_max'                 => '単価上限',
            'rate_note'                => '単価備考',
            'commercial_flow'          => '商流',
            'work_style'               => '稼働形態',
            'work_location_line'       => '路線名',
            'work_location_station'    => '最寄駅',
            'interview_count'          => '面談回数',
            'negotiation_required'     => '顧客折衝経験要否',
            'description'              => '業務内容詳細',
            'work_env'                 => '稼働環境',
            'billing_range'            => '精算幅',
            'remarks'                  => '特記事項',
            'required_skills'          => '必須スキル',
            'required_skills.*.label'  => '必須スキル名',
            'required_skills.*.detail' => '必須スキル詳細',
            'preferred_skills'         => '尚可スキル',
            'preferred_skills.*.label' => '尚可スキル名',
            'preferred_skills.*.detail'=> '尚可スキル詳細',
            'proc_requirements'        => '要件定義',
            'proc_basic_design'        => '基本設計',
            'proc_detail_design'       => '詳細設計',
            'proc_development'         => '開発',
            'proc_testing'             => 'テスト',
            'proc_maintenance'         => '保守運用',
        ];
    }
}
