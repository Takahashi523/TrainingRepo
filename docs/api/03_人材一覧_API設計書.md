# 人材一覧API設計書

## API基本情報

### API名
- 人材一覧取得API

### エンドポイント
GET /api/engineers

### 概要
- 人材一覧を取得する。
- 検索条件（ステータス・スキル・勤務形態・工程）による複数選択の絞り込みおよびソートが可能。

## リクエスト仕様
### 検索項目（クエリ）
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| status_ids | array | 任意 | ステータスID配列 |
| skill_ids | array | 任意 | スキルID配列 |
| work_type_ids | array | 任意 | 勤務形態ID配列 |
| phase_ids | array | 任意 | 工程ID配列 |

### ソート
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| sort | string | 任意 | ソート項目（例：更新日, 稼働可能時期）|
| order | string | 任意 | 並び順（asc / desc）|

### ページネーション
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| page | integer | 任意 | ページ番号（デフォルト：1） |
| per_page | integer | 任意 | 1ページあたりの件数（デフォルト：4）|

## レスポンス仕様

### レスポンス項目定義
- 名前
- 年齢（生年月日から算出）
- 最寄駅
- 路線
- スキル（複数）
- 担当営業
  - 主担当（未設定の可能性あり）
  - サブ担当（未設定の可能性あり）
- 工程経験（複数）
- 勤務形態（複数）
- ステータス
- 稼働可能日
- 最終更新日

### レスポンスメタ情報
- 総件数（total）
- 現在の表示範囲（例：1〜4件 / 50件）
- 現在ページ

### 保存検索条件
- 保存検索条件（ユーザーと紐づく）

## レスポンス例
```
JSON
{
  "data": [
    {
      "id": 1,
      "name": "山田太郎",
      "age": 30,
      "nearest_station": {
        "id": 10,
        "name": "渋谷"
      },
      "route": {
        "id": 3,
        "name": "山手線"
      },
      "skills": [
        { "id": 1, "name": "Java" },
        { "id": 2, "name": "AWS" }
      ],
      "users": {
        "main": {
          "id": 1,
          "name": "佐藤"
        },
        "sub": [
          {
            "id": 2,
            "name": "鈴木"
          }
        ]
      },
      "phases": [
        { "id": 1, "name": "基本設計" }
      ],
      "work_types": [
        { "id": 1, "name": "常駐" },
        { "id": 2, "name": "リモート可" }
      ],
      "status": {
        "id": 1,
        "name": "稼働中"
      },
      "available_date": "2026-05-01",
      "updated_at": "2026-04-01"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 100,
    "from": 1,
    "to": 20
  },
  "saved_filters": [
    {
      "id": 1,
      "label": "Java × 提案可 × フルリモート",
      "conditions": {
        "skill_ids": [1],
        "status_ids": [1],
        "work_type_ids": [1]
      }
    }
  ]
}
```

## 関連データ取得
### 検索条件マスタ
検索条件の選択肢は以下のマスタから取得する
- statuses（ステータス一覧）
- skills（スキル一覧）
- work_types（勤務形態一覧）
- phases（工程一覧）

## 処理概要
1. engineersテーブルを基点にクエリ生成

1. 検索条件による絞り込み（複数選択対応）
   1. status_ids → whereIn
   1. skill_ids → whereHas + whereIn
   1. work_type_ids → whereHas + whereIn
   1. phase_ids → whereHas + whereIn

1. ソート条件適用
   ・sort + order

1. ページネーション適用
   ・page, per_page

1. 関連テーブルを取得
   ・skills
   ・phases
   ・work_types
   ・status
   ・station
   ・route

1. JSON形式で返却

## 使用テーブル
| テーブル名 | 日本語名 | 説明 |
|-----------|---------|------|
| engineers | 人材 | 人材の基本情報 |
| skills | スキルマスタ | スキル一覧 |
| engineer_skill | 人材スキル | 人材とスキルの中間テーブル |
| phases | 工程マスタ | 開発工程（要件定義、設計など） |
| engineer_phase | 人材工程経験 | 人材と工程の中間テーブル |
| work_types | 勤務形態マスタ | 常駐・リモートなど |
| engineer_work_type | 人材勤務形態 | 人材と勤務形態の中間テーブル |
| statuses | ステータスマスタ | 稼働中・待機中など |
| users | ユーザー | 営業担当者など |
| engineer_user | 人材担当営業 | 人材と営業の中間テーブル（主・サブ） |
| saved_filters | 保存検索条件 | ユーザーごとの検索条件保存 |

## 未確定事項（TBD）
- ソート項目の最終定義（updated_at / available_date など）
- 1ページあたり件数の上限
- 保存検索条件の上限件数
- 検索条件の論理条件（AND検索 / OR検索 の仕様）
  - 例：スキル[Java, PHP]で検索する場合、Java OR PHPか、Java AND PHPか。
- 最寄駅のマスタ連携方式未定