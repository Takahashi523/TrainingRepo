# 人材一覧API設計書

## API基本情報

### API名
- 人材一覧取得API

### エンドポイント
`
GET /api/engineers
`

### 概要
- 人材一覧を取得する。
- 検索条件（ステータス・スキル・勤務形態・工程経験）による複数選択の絞り込みおよびソートが可能。

## リクエスト仕様
### 検索項目（クエリ）
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| status_ids | array | 任意 | ステータスID配列 |
| skill_ids | array | 任意 | スキルID配列 |
| work_types | array | 任意 | 勤務形態（キー配列） |
| phases | array | 任意 | 工程経験（キー配列） |

### ソート
| パラメータ名 | 型 | 必須 | 説明 |
|---|---|---|---|
| sort | string | 任意 | ソート項目（DBカラム名 例：updated_at, available_date）|
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
- 担当営業（主・サブ）
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
        "sub": {
          "id": 2,
          "name": "鈴木"
        }
      },
      "phases": [
        { "key": "requirement_definition", "name": "要件定義", "has_experience": false },
        { "key": "basic_design", "name": "基本設計", "has_experience": true },
        { "key": "detailed_design", "name": "詳細設計", "has_experience": true },
        { "key": "development", "name": "開発", "has_experience": true },
        { "key": "test", "name": "テスト", "has_experience": true },
        { "key": "maintenance", "name": "保守運用", "has_experience": false }
      ],
      "work_types": [
        { "key": "onsite", "name": "常駐可"},
        { "key": "partial_remote", "name": "一部リモート可"}
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
    "per_page": 4,
    "total": 50,
    "from": 1,
    "to": 4
  },
  "saved_filters": [
    {
      "id": 1,
      "label": "Java × 提案可 × フルリモート",
      "conditions": {
        "skill_ids": [1],
        "status_ids": [1],
        "work_types": ["onsite"]
      }
    }
  ]
}
```

## レスポンスメタ
| 項目名 | 型 | 説明 |
|---|---|---|
| current_page | integer | 現在のページ番号 |
| per_page | integer | 1ページあたりの件数 |
| total | integer | 全件数 |
| from | integer | 現在ページの開始位置 |
| to | integer | 現在ページの終了位置 |

## 検索仕様
### 条件ロジック
- 同一項目内：OR
- 異なる項目間：AND

例：

`
(Java OR PHP) AND (稼働中) AND (フルリモート)
`
### クエリ詳細
| 条件 | 実装 |
|---|---|
| status_ids | whereIn |
| skill_ids | whereHas（OR）|
| work_types | whereJsonContains |
| phases | where('phases->key', true) |

### 削除データの扱い
- status = 削除（無効）は除外する

## 処理概要
1. engineersテーブルを基点にクエリ生成
1. 削除データ除外
1. 検索条件適用
1. ソート条件適用
1. ページネーション適用
1. 関連テーブルを取得
   - skills
   - status
   - station
   - route
   - user (main_user_id / sub_user_id から取得)
1. レスポンス整形
1. JSON形式で返却

## 使用テーブル
| テーブル名 | 日本語名 | 説明 |
|-----------|---------|------|
| engineers | 人材 | 人材の基本情報 |
| skills | スキルマスタ | スキル一覧 |
| engineer_skill | 人材スキル | 人材とスキルの中間テーブル |
| statuses | ステータスマスタ | 提案可 / 面談中 / 稼働中 / 退職等など |
| users | ユーザー | 営業担当 |
| saved_filters | 保存検索条件 | ユーザーごとの検索条件保存 |

## 未確定事項（TBD）
- ソート項目の最終定義（更新日, 稼働可能日 など）
- 1ページあたり件数の上限
- 保存検索条件の上限件数
- 検索条件の論理条件（AND検索 / OR検索 の仕様）
  - 同一項目内（skill_ids, work_types, phases）は OR 条件
  - 異なる項目間は AND 条件
  - 例：（Java OR PHP）AND（提案可）AND（フルリモート）
  - 最終仕様は要確認（TBD）
- 最寄駅のマスタ連携方式
- 人材のステータスの管理方法（固定 or マスタ管理）
- 年齢の計算タイミング（API側 or フロント側）
- 管理者の削除データ表示可否