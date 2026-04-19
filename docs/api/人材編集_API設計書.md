# 人材編集API設計書

## API基本情報

### API名
- 人材編集API

### エンドポイント
`
PATCH /api/engineers/{id}
`

### 概要
- 指定した人材情報を更新する。

## リクエスト仕様

### パスパラメータ
| パラメータ名 | 型       | 必須 | 説明   |
| ------ | ------- | -- | ---- |
| id     | integer | 必須 | 人材ID |

### 人材基本（engineers）
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| name | string | 任意 | 氏名 |
| name_kana | string | 任意 | カナ |
| birth_date | date | 任意 | 生年月日 |
| nearest_station_id | integer | 任意 | 最寄駅ID |
| route_id | integer | 任意 | 路線ID |
| available_date | date | 任意 | 稼働可能日 |
| phases | object（連想配列） | 任意 | 工程経験（キー: boolean）|
| client_communication_experience | boolean | 任意 | 顧客折衝経験 |
| self_promotion | string | 任意 | アピールポイント（最大1000文字） |
| resume_file | file  | 任意 | 職務経歴書ファイル |
| desired_monthly_rate | integer | 任意 | 希望単価（月額・万円） |
| work_types | array | 任意 | 勤務形態（キー配列）|
| notes | string | 任意 | 特記事項（最大1000文字） |
| status_id | integer | 任意 | ステータスID |

### 人材スキル（engineer_skill）
| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| skills | array | 任意 | スキル配列 |
| skills[].skill_id | integer | 必須（skills指定時） | スキルID |
| skills[].experience_years | integer | 必須（skills指定時） | 経験年数 |

### 人材担当営業（engineer_user）
| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| main_user_id | integer | 任意 | 主営業担当ID |
| sub_user_id | integer | 任意 | サブ営業担当ID |

### リクエスト例
```
JSON
{
  "name": "山田太郎",
  "available_date": "2026-06-01",
  "skills": [
    { "skill_id": 1, "experience_years": 4 },
    { "skill_id": 3, "experience_years": 2 }
  ],
  "phases": {
    "basic_design": true,
    "development": true
  },
  "work_types": ["full_remote"],
  "main_user_id": 2
}
```

### リクエスト形式
- application/json
- multipart/form-data（ファイルありの場合）

## 更新仕様

### 更新ルール
| 項目 | 挙動 |
|---|---|
| 指定された項目 | 上書き更新 |
| 未指定の項目 | 変更なし |

### スキル更新ルール
- skillsがリクエストに含まれる場合
  - 既存のスキルを全削除
  - 新しいskillsで再登録

### 担当営業更新ルール
- main_user_id / sub_user_id が指定された場合
  - 既存レコードを更新

### ファイル更新ルール
- resume_fileが指定された場合
  - 新規ファイル保存成功後、既存ファイルを削除
  - 保存失敗時は既存ファイルは保持する
- 未指定の場合
  - 変更なし

## レスポンス仕様

### ステータスコード
| ステータス | 説明 |
|---|---|
| 200 | 更新成功 |
| 404 | 対象データなし |
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
  "message": "人材情報を更新しました"
}
```

### バリデーションエラー
```
JSON
{
  "message": "入力内容に誤りがあります",
  "errors": {
    "skills.0.skill_id": ["必須です"],
    "name_kana": ["カナ形式で入力してください"]
  }
}
```

### データ未存在
```
JSON
{
  "message": "対象の人材が存在しません"
}
```

### バリデーション
| パラメータ | ルール | 説明 |
|---|---|---|
| name | sometimes / string | 氏名 |
| name_kana | sometimes / regex | カナのみ |
| birth_date | sometimes / date| 生年月日 |
| available_date | sometimes / date| 稼働可能日 |
| nearest_station_id | sometimes / exists | 駅マスタに存在 |
| route_id | sometimes / exists | 路線マスタに存在 |
| status_id | sometimes / exists | ステータス存在チェック |
| skills | sometimes / array | スキル配列 |
| skills.*.skill_id | required_with:skills / exists | スキルID |
| skills.*.experience_years | required_with:skills / integer / min:0 | 経験年数 |
| phases | sometimes / array | 工程経験（※少なくとも1つはtrue） |
| phases.* | boolean | true / false |
| client_communication_experience | sometimes / boolean | 顧客折衝経験 |
| self_promotion | sometimes / string / max:1000 | PRポイント |
| resume_file | sometimes / file / mimes:pdf / max:5120 | PDFのみ（5MB以内） |
| desired_monthly_rate | sometimes / integer / min:0 | 数値 |
| work_types | sometimes / array | 勤務形態 |
| work_types.* | sometimes / string / in:onsite,partial_remote,full_remote | |
| notes | sometimes / string / max:1000 | 特記事項 |
| main_user_id | sometimes / exists | 主担当 |
| sub_user_id | nullable / exists / different:main_user_id | サブ担当 |

### 処理概要
1. 対象データ存在チェック（存在しなければ404）
1. リクエストバリデーション
1. トランザクション開始
1. engineersテーブル更新
1. engineer_skill更新（必要な場合）
1. engineer_user更新（必要な場合）
1. ファイル更新（必要な場合）
1. コミット
1. レスポンス返却

※エラー発生時はロールバック

## 使用テーブル
| テーブル名 | 日本語名 | 説明 |
|---|---|---|
| engineers | 人材 | 人材の基本情報 |
| engineer_skill | 人材スキル | 人材とスキルの中間テーブル |
| engineer_user | 人材担当営業 | 人材と営業の中間テーブル（主・サブ） |
| skills | スキルマスタ | スキル一覧 |
| statuses | ステータスマスタ | 稼働中・待機中など |
| users | ユーザー | 営業担当 |

## 未確定事項（TBD）
- スキル更新を「差分更新」にするか（現在は全削除→再登録）
- ファイルの物理削除タイミング
- 人材のステータスの管理方法（固定 or マスタ管理）