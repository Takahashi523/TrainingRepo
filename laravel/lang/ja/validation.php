<?php

return [
 
    /*
    |--------------------------------------------------------------------------
    | バリデーション言語ファイル
    |--------------------------------------------------------------------------
    */
 
    'accepted'             => ':attributeを承認してください。',
    'accepted_if'          => ':otherが:valueの場合、:attributeを承認してください。',
    'active_url'           => ':attributeに有効なURLを入力してください。',
    'after'                => ':attributeには:dateより後の日付を入力してください。',
    'after_or_equal'       => ':attributeには:date以降の日付を入力してください。',
    'alpha'                => ':attributeには英字のみ入力してください。',
    'alpha_dash'           => ':attributeには英字・数字・ハイフン・アンダースコアのみ入力してください。',
    'alpha_num'            => ':attributeには英字・数字のみ入力してください。',
    'array'                => ':attributeには配列を入力してください。',
    'ascii'                => ':attributeには半角英数字と記号のみ入力してください。',
    'before'               => ':attributeには:dateより前の日付を入力してください。',
    'before_or_equal'      => ':attributeには:date以前の日付を入力してください。',
    'between'              => [
        'array'   => ':attributeの件数は:min〜:max件にしてください。',
        'file'    => ':attributeのファイルサイズは:min〜:maxKBにしてください。',
        'numeric' => ':attributeは:min〜:maxの範囲で入力してください。',
        'string'  => ':attributeは:min〜:max文字で入力してください。',
    ],
    'boolean'              => ':attributeには正しい値を入力してください。',
    'confirmed'            => ':attributeの確認入力が一致しません。',
    'current_password'     => 'パスワードが正しくありません。',
    'date'                 => ':attributeに正しい日付を入力してください。',
    'date_equals'          => ':attributeには:dateと同じ日付を入力してください。',
    'date_format'          => ':attributeは:formatの形式で入力してください。',
    'decimal'              => ':attributeには小数点以下:decimal桁の数値を入力してください。',
    'declined'             => ':attributeを拒否してください。',
    'declined_if'          => ':otherが:valueの場合、:attributeを拒否してください。',
    'different'            => ':attributeと:otherには異なる値を入力してください。',
    'digits'               => ':attributeは:digits桁で入力してください。',
    'digits_between'       => ':attributeは:min〜:max桁で入力してください。',
    'dimensions'           => ':attributeの画像サイズが不正です。',
    'distinct'             => ':attributeに重複した値が含まれています。',
    'doesnt_end_with'      => ':attributeには:valuesで終わる値は入力できません。',
    'doesnt_start_with'    => ':attributeには:valuesで始まる値は入力できません。',
    'email'                => ':attributeに有効なメールアドレスを入力してください。',
    'ends_with'            => ':attributeには:valuesのいずれかで終わる値を入力してください。',
    'enum'                 => '選択された:attributeは無効です。',
    'exists'               => '選択された:attributeは存在しません。',
    'extensions'           => ':attributeには:valuesのいずれかの拡張子のファイルを選択してください。',
    'file'                 => ':attributeにはファイルを選択してください。',
    'filled'               => ':attributeを入力してください。',
    'gt'                   => [
        'array'   => ':attributeは:value件より多くしてください。',
        'file'    => ':attributeのファイルサイズは:valueKBより大きくしてください。',
        'numeric' => ':attributeには:valueより大きい値を入力してください。',
        'string'  => ':attributeは:value文字より多く入力してください。',
    ],
    'gte'                  => [
        'array'   => ':attributeは:value件以上にしてください。',
        'file'    => ':attributeのファイルサイズは:valueKB以上にしてください。',
        'numeric' => ':attributeには:value以上の値を入力してください。',
        'string'  => ':attributeは:value文字以上入力してください。',
    ],
    'hex_color'            => ':attributeに有効な16進数カラーコードを入力してください。',
    'image'                => ':attributeには画像ファイルを選択してください。',
    'in'                   => '選択された:attributeは無効です。',
    'in_array'             => ':attributeに:otherに存在しない値が含まれています。',
    'integer'              => ':attributeには整数を入力してください。',
    'ip'                   => ':attributeに有効なIPアドレスを入力してください。',
    'ipv4'                 => ':attributeに有効なIPv4アドレスを入力してください。',
    'ipv6'                 => ':attributeに有効なIPv6アドレスを入力してください。',
    'json'                 => ':attributeに有効なJSON文字列を入力してください。',
    'lowercase'            => ':attributeには小文字のみ入力してください。',
    'lt'                   => [
        'array'   => ':attributeは:value件より少なくしてください。',
        'file'    => ':attributeのファイルサイズは:valueKBより小さくしてください。',
        'numeric' => ':attributeには:valueより小さい値を入力してください。',
        'string'  => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte'                  => [
        'array'   => ':attributeは:value件以下にしてください。',
        'file'    => ':attributeのファイルサイズは:valueKB以下にしてください。',
        'numeric' => ':attributeには:value以下の値を入力してください。',
        'string'  => ':attributeは:value文字以下で入力してください。',
    ],
    'mac_address'          => ':attributeに有効なMACアドレスを入力してください。',
    'max'                  => [
        'array'   => ':attributeは:max件以下にしてください。',
        'file'    => ':attributeのファイルサイズは:maxKB以内にしてください。',
        'numeric' => ':attributeには:max以下の値を入力してください。',
        'string'  => ':attributeは:max文字以内で入力してください。。。',
    ],
    'max_digits'           => ':attributeは:max桁以内で入力してください。',
    'mimes'                => ':attributeには:valuesのいずれかのファイルを選択してください。',
    'mimetypes'            => ':attributeには:valuesのいずれかのファイルを選択してください。',
    'min'                  => [
        'array'   => ':attributeは:min件以上にしてください。',
        'file'    => ':attributeのファイルサイズは:minKB以上にしてください。',
        'numeric' => ':attributeには:min以上の値を入力してください。',
        'string'  => ':attributeは:min文字以上入力してください。',
    ],
    'min_digits'           => ':attributeは:min桁以上で入力してください。',
    'missing'              => ':attributeは送信しないでください。',
    'missing_if'           => ':otherが:valueの場合、:attributeは送信しないでください。',
    'missing_unless'       => ':otherが:valueでない場合、:attributeは送信しないでください。',
    'missing_with'         => ':valuesが存在する場合、:attributeは送信しないでください。',
    'missing_with_all'     => ':valuesがすべて存在する場合、:attributeは送信しないでください。',
    'multiple_of'          => ':attributeには:valueの倍数を入力してください。',
    'not_in'               => '選択された:attributeは無効です。',
    'not_regex'            => ':attributeの形式が正しくありません。',
    'numeric'              => ':attributeには数値を入力してください。',
    'password'             => [
        'letters'       => ':attributeには少なくとも1文字の英字を含めてください。',
        'mixed'         => ':attributeには少なくとも大文字・小文字を1文字ずつ含めてください。',
        'numbers'       => ':attributeには少なくとも1文字の数字を含めてください。',
        'symbols'       => ':attributeには少なくとも1文字の記号を含めてください。',
        'uncompromised' => '入力された:attributeは情報漏洩のリスクがあります。別の:attributeを入力してください。',
    ],
    'present'              => ':attributeを入力してください。',
    'present_if'           => ':otherが:valueの場合、:attributeを入力してください。',
    'present_unless'       => ':otherが:valueでない場合、:attributeを入力してください。',
    'present_with'         => ':valuesが存在する場合、:attributeを入力してください。',
    'present_with_all'     => ':valuesがすべて存在する場合、:attributeを入力してください。',
    'prohibited'           => ':attributeは入力できません。',
    'prohibited_if'        => ':otherが:valueの場合、:attributeは入力できません。',
    'prohibited_unless'    => ':otherが:valuesでない場合、:attributeは入力できません。',
    'prohibits'            => ':attributeが存在する場合、:otherは入力できません。',
    'regex'                => ':attributeの形式が正しくありません。',
    'required'             => ':attributeは必須です。',
    'required_array_keys'  => ':attributeには:valuesのキーが必要です。',
    'required_if'          => ':otherが:valueの場合、:attributeを入力してください。',
    'required_if_accepted' => ':otherが承認されている場合、:attributeを入力してください。',
    'required_unless'      => ':otherが:valuesでない場合、:attributeを入力してください。',
    'required_with'        => ':valuesを入力する場合、:attributeも入力してください。',
    'required_with_all'    => ':valuesをすべて入力する場合、:attributeも入力してください。',
    'required_without'     => ':valuesを入力しない場合、:attributeを入力してください。',
    'required_without_all' => ':valuesをすべて入力しない場合、:attributeを入力してください。',
    'same'                 => ':attributeと:otherには同じ値を入力してください。',
    'size'                 => [
        'array'   => ':attributeは:size件にしてください。',
        'file'    => ':attributeのファイルサイズは:sizeKBにしてください。',
        'numeric' => ':attributeには:sizeを入力してください。',
        'string'  => ':attributeは:size文字で入力してください。',
    ],
    'starts_with'          => ':attributeには:valuesのいずれかで始まる値を入力してください。',
    'string'               => ':attributeには文字列を入力してください。',
    'timezone'             => ':attributeに有効なタイムゾーンを入力してください。',
    'unique'               => ':attributeはすでに使用されています。',
    'uploaded'             => ':attributeのアップロードに失敗しました。',
    'uppercase'            => ':attributeには大文字のみ入力してください。',
    'url'                  => ':attributeに有効なURLを入力してください。',
    'ulid'                 => ':attributeに有効なULIDを入力してください。',
    'uuid'                 => ':attributeに有効なUUIDを入力してください。',
 
    /*
    |--------------------------------------------------------------------------
    | カスタムバリデーションメッセージ
    | 'field' => ['rule' => 'message'] の形式で定義する
    |--------------------------------------------------------------------------
    */
 
    'custom' => [
        // 案件登録・編集
        // 'client_name' => [
        //     'max' => '顧客名は100文字以内で入力してください。',
        // ],
        'headcount' => [
            'integer' => '正しい人数を入力してください。',
            'min'     => '正しい人数を入力してください。',
        ],
        'start_date' => [
            'date' => '正しい日付を入力してください。',
        ],
        'rate_min' => [
            'required' => '単価下限を入力してください。',
            'integer'  => '正しい金額を入力してください。',
            'min'      => '正しい金額を入力してください。',
            'lte'      => '単価下限は単価上限以下の値を入力してください。',
        ],
        'rate_max' => [
            'required' => '単価上限を入力してください。',
            'integer'  => '正しい金額を入力してください。',
            'min'      => '正しい金額を入力してください。',
            'gte'      => '単価上限は単価下限以上の値を入力してください。',
        ],
        // 'rate_note' => [
        //     'max' => '単価備考は100文字以内で入力してください。',
        // ],
        'commercial_flow' => [
            'in' => '商流を選択してください。',
        ],
        'work_style' => [
            'in' => '稼働形態を選択してください。',
        ],
        'work_location_line' => [
            'max' => '路線名は100文字以内で入力してください。',
        ],
        'work_location_station' => [
            'required'    => '常駐・一部リモートの場合は最寄駅を入力してください。',
            'required_if' => '常駐・一部リモートの場合は最寄駅を入力してください。',
            'max'         => '最寄駅は100文字以内で入力してください。',
        ],
        'interview_count' => [
            'integer' => '正しい回数を入力してください。',
            'min'     => '正しい回数を入力してください。',
        ],
        'negotiation_required' => [
            'boolean' => '顧客折衝経験要否を正しく選択してください。',
        ],
 
        // スキル（案件）
        'required_skills.*.label' => [
            'max'           => 'スキル名は15文字以内で入力してください。',
        ],
        'required_skills.*.detail' => [
            'max' => 'スキル詳細は500文字以内で入力してください。',
        ],
        'preferred_skills.*.label' => [
            'max'           => 'スキル名は15文字以内で入力してください。',
        ],
        'preferred_skills.*.detail' => [
            'max' => 'スキル詳細は500文字以内で入力してください。',
        ],
 
        // 担当営業（共通）
        'main_user_id' => [
            'required' => '主担当を選択してください。',
            'exists'   => '主担当に存在するユーザーを選択してください。',
        ],
        'sub_user_id' => [
            'exists'    => 'サブ担当に存在するユーザーを選択してください。',
            'different' => '主担当と異なるユーザーを選択してください。',
        ],
 
        // ステータス（人材）
        'status' => [
            'required' => 'ステータスを選択してください。',
            'in'       => 'ステータスを選択してください。',
        ],
    ],
 
    /*
    |--------------------------------------------------------------------------
    | カスタム属性名
    | FormRequest の attributes() メソッドで上書きするため、
    | ここでは汎用的な名称のみ定義する
    |--------------------------------------------------------------------------
    */
 
    'attributes' => [],
];