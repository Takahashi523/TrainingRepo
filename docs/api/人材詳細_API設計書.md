# 人材詳細API設計書

## API基本情報

### API名
- 人材詳細取得API

### エンドポイント
`
GET /api/engineers/{id}
`

### 概要
- 指定した人材の詳細情報を取得する。

## リクエスト仕様

### パスパラメータ
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| id | integer | 必須 | 人材ID |

## レスポンス仕様

### レスポンス項目定義
- 名前
- 年齢（生年月日から算出 ※レスポンス時点の満年齢）
- ステータス
- 稼働可能日
- 最寄駅
- 路線
- 担当営業
  - 主担当
  - サブ担当（nullable）
- スキル（複数）
  - スキル名
  - 経験年数
- 工程経験（複数）
- 顧客折衝経験
- アピールポイント
- 職務経歴書ファイル（ダウンロードURL）
- 希望単価（月額）
- 勤務形態（複数）
- 特記事項
- 最終更新日

## レスポンス例
```
JSON
{
  "data": {
    "id": 1,
    "name": "山田太郎",
    "age": 30,
    "status": {
      "id": 1,
      "name": "稼働中"
    },
    "available_date": "2026-05-01",
    "nearest_station": {
      "id": 10,
      "name": "渋谷"
    },
    "route": {
      "id": 3,
      "name": "山手線"
    },
    "users": [
      {
        "id": 1,
        "name": "佐藤"
        "role": "main"
      },
      {
        "id": 2,
        "name": "鈴木"
        "role": "sub"
      }
    ],
    "skills": [
      {
        "id": 1,
        "name": "Java",
        "experience_years": 3
      },
      {
        "id": 2,
        "name": "AWS",
        "experience_years": 1
      }
    ],
    "phases": [
      { "key": "requirement_definition", "name": "要件定義", "has_experience": false },
      { "key": "basic_design", "name": "基本設計", "has_experience": true },
      { "key": "detailed_design", "name": "詳細設計", "has_experience": false },
      { "key": "development", "name": "開発", "has_experience": true },
      { "key": "test", "name": "テスト", "has_experience": false },
      { "key": "maintenance", "name": "保守運用", "has_experience": false }
    ],
    "client_communication_experience": true,
    "self_promotion": "Java・Spring Boot経験あり",
    "resume_file": {
      "file_name": "職務経歴書_山田太郎_202501.pdf",
      "download_url": "/api/engineers/1/resume"
    },
    "desired_monthly_rate": 60,
    "work_types": [
      { "key": "onsite", "name": "常駐可" }
    ],
    "notes": "土日祝休み希望",
    "updated_at": "2026-04-01"
  }
}
```

## ステータスコード
| ステータス | 説明 |
|---|---|
| 200 | 取得成功 |
| 404 | 対象データなし |
| 500 | サーバーエラー |

## レスポンス例（エラー）
```
JSON
{
  "message": "対象の人材が存在しません"
}
```

## 職務経歴書ダウンロード仕様

### エンドポイント
`
GET /api/engineers/{id}/resume
`

### 概要
- 職務経歴書ファイルをダウンロードする。

### 挙動
- ファイルが存在する場合：ダウンロード
- 存在しない場合：404

### ステータスコード
| ステータス | 説明 |
|---|---|
| 200 | ダウンロード成功 |
| 404 | ファイルなし |
| 500 | サーバーエラー |

## 処理概要
1. 対象データ存在チェック（存在しなければ404）
1. engineersテーブルから基本情報取得
1. 関連データ取得
   - skills
   - statuses
   - stations
   - routes
   - users（主・サブ）
1. JSON整形
1. レスポンス返却

## 使用テーブル
| テーブル名 | 日本語名 | 説明 |
|---|---|---|
| engineers | 人材 | 人材の基本情報 |
| skills | スキルマスタ | スキル一覧 |
| engineer_skill | 人材スキル | スキル＋経験年数 |
| statuses | ステータスマスタ | ステータス |
| users | ユーザー | 営業担当 |
| engineer_user | 人材担当営業 | 主・サブ |
| stations | 駅マスタ | 最寄駅 |
| routes | 路線マスタ | 路線 |

## 未確定事項（TBD）
- 最寄駅のマスタ連携方式
- 人材のステータスの管理方法（固定 or マスタ管理）
- 年齢の計算タイミング（API側 or フロント側）