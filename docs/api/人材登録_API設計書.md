# 人材登録API設計書

## API基本情報

### API名
- 人材登録API

### エンドポイント
`
POST /api/engineers
`

### 概要
- 人材情報を新規登録する。

## リクエスト仕様

### 人材基本（engineers）
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| name | string | 必須 | 氏名 |
| name_kana | string | 必須 | カナ |
| birth_date | date | 必須 | 生年月日 |
| nearest_station_id | integer | 必須 | 最寄駅ID |
| route_id | integer | 必須 | 路線ID |
| available_date | date | 必須 | 稼働可能日 |
| phases | object（連想配列） | 必須 | 工程経験（キー: boolean） |
| client_communication_experience | boolean | 必須 | 顧客折衝経験 |
| self_promotion | string | 任意 | アピールポイント |
| resume_file | file | 任意 | 職務経歴書PDF |
| desired_monthly_rate | integer | 任意 | 希望単価（月額・万円） |
| work_types | array | 任意 | 勤務形態（キー配列） |
| notes | string | 任意 | 特記事項 |
| status_id | integer | 必須 | ステータスID |

### 人材スキル（engineer_skill）
| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| skills | array   | 必須 | スキル配列 |
| skills[].skill_id | integer | 必須 | スキルID |
| skills[].experience_years | integer | 必須 | 経験年数  |

### 人材担当営業（engineer_user）
| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| main_user_id | integer | 必須 | 主営業担当ID |
| sub_user_id | integer | 任意 | サブ営業担当ID |

### リクエスト例
```
JSON
{
  "name": "山田太郎",
  "name_kana": "ヤマダタロウ",
  "birth_date": "1990-01-01",
  "nearest_station_id": 10,
  "route_id": 3,
  "available_date": "2026-05-01",
  "skills": [
    { "skill_id": 1, "experience_years": 3 },
    { "skill_id": 2, "experience_years": 1 }
  ],
  "phases": {
    "requirement_definition": false,
    "basic_design": true,
    "detailed_design": false,
    "development": true,
    "test": false,
    "maintenance": false
  },
  "client_communication_experience": true,
  "self_promotion": "Java・Spring Boot経験あり",
  "desired_monthly_rate": 60,
  "work_types": ["onsite"],
  "notes": "土日祝休み希望",
  "status_id": 1,
  "main_user_id": 1,
  "sub_user_id": 2
}
```

### リクエスト形式
- application/json
- multipart/form-data（ファイルありの場合）

## レスポンス仕様

### ステータスコード
| ステータス | 説明 |
|---|---|
| 201 | 登録成功 |
| 422 | バリデーションエラー |
| 500 | サーバーエラー |

## レスポンス例

### 成功レスポンス
```
JSON
{
  "data": {
    "id": 1
  },
  "message": "人材を登録しました"
}
```

### バリデーションエラー
```
JSON
{
  "message": "入力内容に誤りがあります",
  "errors": {
    "name": ["必須です"],
    "skills": ["1件以上必要です"],
    "skills.0.skill_id": ["必須です"]
  }
}
```
※配列項目は skills.0.skill_id のような形式で返却される

## バリデーション
| パラメータ | ルール | 説明 |
|---|---|---|
| name | required | 氏名 |
| name_kana | required / regex | カナのみ |
| birth_date | required / date | 生年月日 |
| available_date | required / date | 稼働可能日 |
| nearest_station_id | required / exists | 駅マスタに存在 |
| route_id | required / exists | 路線マスタに存在 |
| status_id | required / exists | ステータス存在チェック |
| skills | required / array / min:1 | 1件以上必須 |
| skills.*.skill_id | required / exists | スキルID |
| skills.*.experience_years | required / integer / min:0 | 経験年数 |
| phases | required / array | 工程経験 |
| phases.* | boolean | true / false |
| phases |  | ※少なくとも1つはtrueであること |
| client_communication_experience | required / boolean | 顧客折衝経験 |
| self_promotion | string / max:1000 | PRポイント |
| resume_file | file / mimes:pdf / max:5120 | PDFのみ（5MB以内） |
| desired_monthly_rate | integer / min:0 | 数値 |
| work_types | array | 勤務形態 |
| work_types.* | string / in:onsite,partial_remote,full_remote | 定義済みキーを使用 |
| notes | string / max:1000 | 特記事項 |
| main_user_id | required / exists | 主担当 |
| sub_user_id | nullable / different:main_user_id | サブ担当 |
※work_typesの値は定数または設定ファイルで管理する

## 処理概要
1. リクエストバリデーション
1. トランザクション開始
1. engineersテーブルに登録
1. engineer_skillテーブルに登録
1. engineer_userテーブルに登録
1. ファイル保存（任意）
   - アップロード後、ストレージに保存しファイルパスをDBに保持する
1. コミット
1. レスポンス返却
※エラー発生時はロールバックする

## 使用テーブル
| テーブル名 | 日本語名 | 説明 |
|-----------|---------|------|
| engineers | 人材 | 人材の基本情報 |
| skills | スキルマスタ | スキル一覧 |
| engineer_skill | 人材スキル | 人材とスキルの中間テーブル |
| statuses | ステータスマスタ | 稼働中・待機中など |
| users | ユーザー | 営業担当者など |
| engineer_user | 人材担当営業 | 人材と営業の中間テーブル（主・サブ） |

## 未確定事項（TBD）
- 最寄駅のマスタ連携方式
- 職務経歴のファイル形式未定
- 人材のステータスの管理方法（固定 or マスタ管理）