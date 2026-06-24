<?php

namespace App\Http\Requests;

use App\Models\FormFieldSetting;
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
        // ----------------------------------------------------------------
        // 第1層：システム固定必須（DBレベルでNOT NULL・ハードコード）
        // ----------------------------------------------------------------
        $rules = [
            'name'         => ['required', 'string', 'max:255'],
            'status'       => ['required', 'in:open,closed,pending'],
            'main_user_id' => ['required', 'exists:users,id'],
        ];

        // ----------------------------------------------------------------
        // 第2層：form_field_settings を参照して動的に組み立て
        // field_key は form_field_settings テーブルの field_key カラムと1対1で対応する
        // ----------------------------------------------------------------
        $dynamicFields = [
            'client_name'       => ['string', 'max:100'],
            'headcount'         => ['integer', 'min:0'],
            'start_date'        => ['date'],
            'commercial_flow'   => ['in:prime,secondary,tertiary,other'],
            'work_style'        => ['in:onsite,hybrid,remote'],
            'interview_count'   => ['integer', 'min:0'],
            'negotiation_required' => ['boolean'],
            'description'       => ['string'],
            'work_env'          => ['string'],
            'billing_range'     => ['string', 'max:100'],
            'remarks'           => ['string'],
        ];

        foreach ($dynamicFields as $field => $fieldRules) {
            $isRequired = FormFieldSetting::isRequired('project', $field);
            $rules[$field] = array_merge(
                [$isRequired ? 'required' : 'nullable'],
                $fieldRules
            );
        }

                // ----------------------------------------------------------------
        // 単価：rate フィールドキー1つで rate_min / rate_max / rate_note をまとめて制御する
        // rate_is_negotiable が true の場合は rate_min / rate_max をバリデーション対象外とする
        // ----------------------------------------------------------------
        $rules['rate_is_negotiable'] = ['nullable', 'boolean'];
        $rules['rate_note']          = ['nullable', 'string', 'max:100'];
 
        $rateRequired = FormFieldSetting::isRequired('project', 'rate');
 
        if ($this->boolean('rate_is_negotiable')) {
            // スキル見合いの場合：rate_min / rate_max は送信不要（null として扱う）
            $rules['rate_min'] = ['nullable', 'integer', 'min:0'];
            $rules['rate_max'] = ['nullable', 'integer', 'min:0'];
        } else {
            // 通常の場合：rate の動的設定 + 上下限の大小関係バリデーション
            $rules['rate_min'] = [
                $rateRequired ? 'required' : 'nullable',
                'integer',
                'min:0',
                'lte:rate_max',
            ];
            $rules['rate_max'] = [
                $rateRequired ? 'required' : 'nullable',
                'integer',
                'min:0',
                'gte:rate_min',
            ];
        }

        // ----------------------------------------------------------------
        // 勤務地：work_location フィールドキー1つで line / station をまとめて制御する
        // work_location_station の条件付き必須は業務ルール固定（form_field_settings 対象外）
        //   - work_style が onsite / hybrid の場合 → 必須
        //   - work_style が remote の場合         → バリデーション対象外
        // ----------------------------------------------------------------
        $workLocationRequired = FormFieldSetting::isRequired('project', 'work_location');
 
        $rules['work_location_line'] = [
            $workLocationRequired ? 'required' : 'nullable',
            'string',
            'max:100',
        ];
 
        $workStyle = $this->input('work_style');
        if (in_array($workStyle, ['onsite', 'hybrid'], true)) {
            // 常駐・一部リモートの場合は最寄駅を業務ルールで必須とする
            $rules['work_location_station'] = ['required', 'string', 'max:100'];
        } else {
            // remote または未選択の場合は nullable
            $rules['work_location_station'] = ['nullable', 'string', 'max:100'];
        }

        // ----------------------------------------------------------------
        // スキル：required_skills / preferred_skills フィールドキーで個別制御
        // ----------------------------------------------------------------
        $requiredSkillsRequired  = FormFieldSetting::isRequired('project', 'required_skills');
        $preferredSkillsRequired = FormFieldSetting::isRequired('project', 'preferred_skills');
 
        $rules['required_skills']           = [$requiredSkillsRequired ? 'required' : 'nullable', 'array'];
        $rules['required_skills.*.label']   = ['string', 'max:15'];
        $rules['required_skills.*.detail']  = ['nullable', 'string', 'max:500'];
 
        $rules['preferred_skills']          = [$preferredSkillsRequired ? 'required' : 'nullable', 'array'];
        $rules['preferred_skills.*.label']  = ['string', 'max:15'];
        $rules['preferred_skills.*.detail'] = ['nullable', 'string', 'max:500'];

                // ----------------------------------------------------------------
        // 対象工程：proc_experience の1設定で proc_* 6フィールドをまとめて制御する
        // WF_12のフォーム設定タブでも「対象工程」として1つのトグルで管理する
        // ----------------------------------------------------------------
        $procRequired = FormFieldSetting::isRequired('project', 'proc_experience');
        $procFields   = [
            'proc_requirements', 'proc_basic_design', 'proc_detail_design',
            'proc_development',  'proc_testing',      'proc_maintenance',
        ];
        foreach ($procFields as $field) {
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
            $skills = $this->input('required_skills', []);
            foreach ($skills as $index => $skill) {
                if (!empty($skill['detail']) && empty($skill['label'])) {
                    $validator->errors()->add(
                        "required_skills.{$index}.label",
                        'スキル詳細を入力する場合はスキル名も入力してください。'
                    );
                    dd($validator->errors()->all());
                }
            }

            $skills = $this->input('preferred_skills', []);
            foreach ($skills as $index => $skill) {
                if (!empty($skill['detail']) && empty($skill['label'])) {
                    $validator->errors()->add(
                        "preferred_skills.{$index}.label",
                        'スキル詳細を入力する場合はスキル名も入力してください。'
                    );
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name'                     => '案件名',
            'status'                   => 'ステータス',
            'main_user_id'             => '主担当',
            'sub_user_id'              => 'サブ担当',
            'client_name'              => '顧客名',
            'headcount'                => '募集人数',
            'start_date'               => '参画開始時期',
            'rate_is_negotiable'       => 'スキル見合いフラグ',
            'rate_min'                 => '単価下限',
            'rate_max'                 => '単価上限',
            'rate_note'                => '単価備考',
            'commercial_flow'          => '商流',
            'work_style'               => '稼働形態',
            'work_location_line'       => '勤務地（路線）',
            'work_location_station'    => '勤務地（最寄駅）',
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
