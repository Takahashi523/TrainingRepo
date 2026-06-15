<?php

return [
    'custom' => [
        'name' => [
            'required' => '氏名は必須です。',
            'max'      => '氏名は100文字以内で入力してください。',
        ],
        'name_kana' => [
            'required' => 'カナは必須です。',
            'max'      => 'カナは100文字以内で入力してください。',
            'regex'    => 'カナで入力してください。',
        ],
        'status' => [
            'required' => 'ステータスは必須です。',
            'in'       => '有効なステータスを選択してください。',
        ],
        'main_user_id' => [
            'required' => '担当営業（メイン）は必須です。',
            'exists'   => '選択された担当営業が見つかりません。',
        ],
        'sub_user_id' => [
            'exists'    => '選択されたサブ担当営業が見つかりません。',
            'different' => '主担当と異なるユーザーを選択してください。',
        ],
        'birth_date' => [
            'required'         => '生年月日は必須です。',
            'date'             => '正しい日付を入力してください。',
            'before_or_equal'  => '生年月日は今日以前の日付を入力してください。',
        ],
        'nearest_station' => [
            'required' => '最寄駅は必須です。',
            'max'      => '最寄駅は100文字以内で入力してください。',
        ],
        'nearest_line' => [
            'required' => '路線名は必須です。',
            'max'      => '路線名は100文字以内で入力してください。',
        ],
        'available_from' => [
            'required' => '稼働可能時期は必須です。',
            'date'     => '正しい日付を入力してください。',
        ],
        'has_negotiation_exp' => [
            'required' => '顧客折衝経験を選択してください。',
            'boolean'  => '顧客折衝経験の値が不正です。',
        ],
        'appeal_note' => [
            'required' => 'アピールポイントは必須です。',
            'max'      => 'アピールポイントは4000文字以内で入力してください。',
        ],
        'desired_rate' => [
            'required' => '希望単価は必須です。',
            'integer'  => '希望単価は整数で入力してください。',
            'min'      => '希望単価は0以上で入力してください。',
            'max'      => '希望単価の値が上限を超えています。',
        ],
        'work_styles' => [
            'required' => '勤務形態を選択してください。',
        ],
        'work_styles.*' => [
            'in' => '勤務形態の値が不正です。',
        ],
        'proc_requirements' => [
            'required' => '経験工程を入力してください。',
            'boolean'  => '経験工程の値が不正です。',
        ],
        'proc_basic_design' => [
            'required' => '経験工程を入力してください。',
            'boolean'  => '経験工程の値が不正です。',
        ],
        'proc_detail_design' => [
            'required' => '経験工程を入力してください。',
            'boolean'  => '経験工程の値が不正です。',
        ],
        'proc_development' => [
            'required' => '経験工程を入力してください。',
            'boolean'  => '経験工程の値が不正です。',
        ],
        'proc_testing' => [
            'required' => '経験工程を入力してください。',
            'boolean'  => '経験工程の値が不正です。',
        ],
        'proc_maintenance' => [
            'required' => '経験工程を入力してください。',
            'boolean'  => '経験工程の値が不正です。',
        ],
        'remarks' => [
            'required' => '特記事項は必須です。',
            'max'      => '特記事項は1000文字以内で入力してください。',
        ],
        'skills' => [
            'required' => 'スキルを1件以上追加してください。',
        ],
        'skills.*.label' => [
            'required'      => 'スキル名は必須です。',
            'required_with' => 'スキル詳細を入力する場合はスキル名も入力してください。',
            'max'           => 'スキルラベルは15文字以内で入力してください。',
        ],
        'skills.*.detail' => [
            'max' => 'スキル詳細は500文字以内で入力してください。',
        ],
    ],
];
