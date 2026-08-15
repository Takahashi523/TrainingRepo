# Nexus 結合テスト（Laravel-Python）シナリオ

| 項目 | 内容 |
|---|---|
| 対象システム | Nexus（国内SES向け 人材・案件マッチングシステム） |
| ドキュメント種別 | 結合テストシナリオ（V字モデル：狭義の結合テストレベル。Laravel↔Python間の外部インターフェース結合に限定） |
| 対象範囲 | Laravel（`app/Services/Matching/*`, `app/Services/Ai/*`）とPython（マッチング／AI要約エンジン）間のHTTP結合点。画面表示・DB永続化・他機能連携は対象外（該当部分は `Nexus_結合テストシナリオ.md`〔総合テストシナリオ〕を参照） |
| 参照元 | `laravel/app/Services/Matching/HttpMatchingEngineClient.php`／`MatchResult.php`／`MatchingEngineException.php`、`laravel/app/Services/Ai/HttpAiSummaryClient.php`／`AiSummaryResult.php`／`AiSummaryException.php`、既存PHPUnit（`laravel/tests/Feature/MatchingControllerTest.php`／`EngineerControllerTest.php`）、設計書PR#12・QA#48 |
| 作成日 | 2026-08-14 |
| 経緯 | Laravelチームからの提案（「結合テストシナリオ レビュー：スコープと観点の整理について」）を受け、`Nexus_結合テストシナリオ.md` を総合テストシナリオへ位置づけ直した上で、Laravel↔Python間の外部結合を狭く検証する本書を新規に切り出した。既存PHPUnitとの重複を避けるため、提案された5観点は実際のテストコードと突き合わせて再検証し、既にカバー済みの観点（§1）と、真に不足している観点（§2）に整理し直している |
| 改訂 | v1.1（2026-08-15）：Laravelチームからの回答を受けて全面見直し。①LP-ERR-01はLaravelチーム側でPHPUnit追加により対応されるため本書のアクティブシナリオから除外。②LP-CONTRACT-01はフロントエンド（`RankBadge.tsx`／`MatchCard.tsx`）のフォールバック・クランプ実装とPython側（PR #25）のソート・件数上限実装を確認のうえ「バリデーション追加は不要」で結論づけ。③最重要の指摘として、§1「PHPUnitでカバー済み・参照のみ」の前提そのものを見直し。既存PHPUnitは`Http::fake`によるLaravel自身の想定をなぞっているに過ぎず、実際のPython実装（PR #25）とは**error_codeの入れ子構造が食い違っている**ことをソースコードで確認した（詳細は§1参照）。これを受け、§1の項目は実Pythonに対する契約適合確認シナリオ（LP-CONTRACT-02）に格上げした |

---

## 0. 本書の位置づけと前提

### 0.1 なぜ別紙に切り出したか

Laravelチームの指摘どおり、`Nexus_結合テストシナリオ.md` が扱う「12画面横断・DB状態/Props/フラッシュまで確認」という内容はV字モデルでは総合テスト（システムテスト）レベルにあたる。一方、Laravel↔Pythonという2コンポーネント間の結合点そのものを狭く検証するのが本来の結合テストであり、本書がその役割を担う。

### 0.2 既存PHPUnitとの重複排除方針（v1.1で前提を修正）

初版では、Laravelチームが「追加したい観点」として挙げた5点のうち3点を「PHPUnitで厚くカバー済み・参照のみ」として新規シナリオ化を見送った。しかしLaravelチームからの指摘により、この前提自体に見落としがあったことが判明した。

**問題点**：既存PHPUnit（`Http::fake`）が固定している「想定応答」はLaravel自身が書いたものであり、Pythonが実際にそのとおり返すかどうかは検証していない。証明されているのは「想定が正しければLaravelは正しく振る舞う」までで、想定自体の正しさ（契約のPython側）は別途確認が必要。

**実例（確認済み）**：Python実装（PR #25、`python/app/routers/matching.py`）は`EngineerNotFoundError`／`NoActiveCandidateError`をルーター内のtry/exceptで捕捉し、`HTTPException(detail={"error_code": ..., "message": ...})`に変換している。FastAPIの既定動作によりこれは`{"detail": {"error_code": ..., "message": ...}}`という**入れ子**のJSONとして返る。一方Laravelの`HttpMatchingEngineClient::mapErrorResponse()`は`$body['error_code']`を**トップレベル**で読むため、実際には常に`null`となり、`ENGINEER_NOT_FOUND`／`NO_ACTIVE_PROJECT`の分岐に入らずすべて`upstream()`に落ちる。`python/app/main.py`には同じ例外に対する正しい形（トップレベル）のapp-level exception handlerが存在するが、router側のtry/exceptが先に捕捉するため到達しない（到達不能コードになっている）。`python/tests/test_routers.py`（404/422/500は`response.json()["detail"]["error_code"]`、504/400は`response.json()["error_code"]`）を見ても、Python側テストはこの入れ子構造をそのまま正として固定してしまっている。

このため、この方針を以下のとおり修正した。

- **§1（実Pythonとの契約適合を要確認）**：AI要約フロー・IDのみ送信の契約・error_code分岐は、Laravel側のロジックとしてはPHPUnitでカバー済みだが、Python実装との整合は別途確認が必要。特にerror_code分岐は上記の入れ子構造の不整合が実在するため、テスト以前に**実装修正が必要な不具合**として扱う。
- **§2（真のギャップ）**：タイムアウト境界。応答の契約違反耐性（LP-CONTRACT-01）はLaravelチームの調査により「バリデーション追加は不要」と結論済み。422側のerror_code分岐の残り（LP-ERR-01）はLaravelチーム側でPHPUnit追加により対応予定。

### 0.3 前提環境

- マッチングエンジン疎通設定：`config('services.matching_engine.*')`（`connect_timeout` 既定5秒／`timeout` 既定10秒）
- AI要約エンジン疎通設定：`config('services.ai_summary.*')`（`connect_timeout` 既定5秒／`timeout` 既定30秒）
- §2のシナリオは実時間の経過を伴うため、`Http::fake` によるモックでは代替できない。ステージング環境等で疑似エンジン（意図的に応答を遅延させるスタブサーバ）を用意して実施することを前提とする。

---

## 1. Laravel側はPHPUnitでカバー済み／Python側との整合は別途確認が必要

以下はLaravel側の分岐ロジックとしてはPHPUnitで検証済みだが、「Pythonが実際にその形で返すか」は別問題である。特にerror_code分岐は、実際に不整合が確認されているため、テスト計画の前に**実装修正の要否そのものが論点**になる。

| 観点 | Laravel側の検証（PHPUnit） | Python側（PR #25）との整合 |
|---|---|---|
| AI要約フロー（正常/504/空出力/appeal_note連動） | `EngineerControllerTest`の該当テスト群でLaravel側のロジックは検証済み | AI要約（`/api/v1/ai/profile-summary`）のレスポンス形は今回未確認。LP-CONTRACT-02で合わせて確認する |
| IDのみ送信の契約 | `test_engine_receives_only_engineer_id` | 送信側（Laravel）の契約であり、Python側との不整合リスクは低い。新規シナリオ化は見送り継続 |
| error_code分岐（404/422） | `test_engine_404_returns_not_found`等。ただし**フェイクが返す想定JSON自体がLaravel側の想定であり未検証** | **不整合を確認済み**。`python/app/routers/matching.py`が`EngineerNotFoundError`／`NoActiveCandidateError`をtry/exceptで捕捉し`HTTPException(detail={"error_code":..., "message":...})`に変換 → FastAPIの既定挙動で`{"detail": {"error_code": ...}}`という**入れ子**で返る。Laravelの`mapErrorResponse()`は`$body['error_code']`を**トップレベル**で読むため常にnullとなり、404/422いずれも判定に失敗して`upstream()`に落ちる。`main.py`の同名例外向けapp-level handler（トップレベルで返す正しい実装）はrouterのtry/exceptに先取りされ到達不能。`python/tests/test_routers.py`は404/422/500を`response.json()["detail"]["error_code"]`、504/400を`response.json()["error_code"]`とアサートしており、Python内でも形が割れたまま固定されている。OpenAPI宣言（`responses={404: {"model": ErrorResponse}}`、`ErrorResponse`はフラット型）とも矛盾 |

### LP-CONTRACT-02 実Pythonサービスとの契約適合確認（PR #25マージ後に実施）

| 項目 | 内容 |
|---|---|
| 目的 | 実際に稼働するPythonサービス（PR #25、またはそのマージ後の`develop`）に対し、Laravel側が期待するレスポンス形と実際のレスポンス形が一致することを確認する。フィールド名・error_codeの階層・日時フォーマット・空の表現が対象 |
| 事前条件 | PR #25がマージされ、かつ**下記の既知不整合（error_codeの入れ子構造）が是正されていること**。是正前に実施する場合は「既知の不具合として現状を記録する」目的に限定する |
| 前提の確認 | 本シナリオは単なるテストではなく、実装修正の検証を兼ねる。PR #25の修正方針としては、Laravelチームが提示している「router側のtry/exceptを外し、main.pyのapp-level handlerに処理を委ねる」案が最小修正になる見込み |

**手順**
1. 正常系：人材にマッチング候補がある状態で `/engineers/{id}/matching` を表示し、実エンジンからの応答を確認する。
2. 対象なし（422／NO_ACTIVE_PROJECT相当）：候補案件が0件の人材で実行し、`emptyReason: "no_match"`（エラー扱いにならないこと）を確認する。
3. 対象人材なし（404／ENGINEER_NOT_FOUND相当）：Python側に存在しない`engineer_id`で実行し、404として「対象なし」扱いになることを確認する（上流障害として`engine_error`にならないこと）。
4. 上流障害（Bedrockタイムアウト等504）：Python側で意図的にAI呼び出しを失敗させ、`emptyReason: "engine_error"`・`flash.error`になることを確認する。

**期待結果**
- 各パターンで、Laravel側の`mapErrorResponse()`がPythonの実際のレスポンス形（error_codeの階層を含む）を正しく解釈し、意図した`emptyReason`／flashメッセージに分岐する。
- 2・3が誤って`engine_error`（上流障害扱い）にならないこと（＝現状の既知不整合が解消されていること）。
- `generated_at`／`ai_summary_generated_at`のISO8601形式がLaravel側の`Carbon::parse()`で問題なくパースできること。

> 本シナリオは実施前に、上記のerror_code入れ子構造の不整合をPR #25側で是正することが前提となる。Laravelチームより「必要であればPR #25側にも共有する」とのことなので、こちらからも早期の共有・修正依頼を推奨する。

---

## 2. 本書で新規に検証する観点（真のギャップ）

### LP-TIMEOUT-01 タイムアウト境界（connect / read）

| 項目 | 内容 |
|---|---|
| 目的 | `connectTimeout`（既定5秒）・`timeout`（既定10秒）の設定値が実際に機能し、それぞれの境界を超えた場合にLaravel側が上流障害として扱うことを確認する |
| 事前条件 | 疑似マッチングエンジン（応答を意図的に遅延させられるスタブ）を用意し、Laravelから疎通可能な状態にする |

**手順**
1. TCP接続自体が確立しない（またはSYNに応答しない）宛先に対し `matching_engine.base_url` を向け、`/engineers/{id}/matching` を表示する。connectTimeout（5秒）前後の応答時間を計測する。
2. 接続は確立するが、レスポンスボディの返却を10秒超遅延させる疑似エンジンに対し、同様にマッチング画面を表示する。timeout（10秒）前後の応答時間を計測する。
3. 比較として、9秒程度で応答する疑似エンジン（タイムアウト未満）でも同様に確認し、正常応答として扱われることを確認する。

**期待結果**
- 1・2いずれも `ConnectionException` として捕捉され、`MatchingEngineException::upstream()` に変換される。画面は `emptyReason: "engine_error"`・`flash.error`「マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。」で応答が返る（`test_connection_failure_is_treated_as_upstream` と同じ挙動になること）。
- 1の応答時間が概ね5秒前後、2の応答時間が概ね10秒前後であり、無制限に待ち続けないことを確認する。
- 3では正常にマッチング結果が表示される。

---

### LP-ERR-01 422応答のうちNO_ACTIVE_PROJECT以外のerror_codeが上流障害として扱われること（対応状況：Laravelチーム側でPHPUnit追加予定）

422応答が `NO_ACTIVE_PROJECT` の場合のみ「候補0件（no_match）」として扱われ、それ以外のerror_codeは上流障害（engine_error）として区別される、という観点。Laravelチームより「`Http::fake`で完結するため、`test_no_active_project_shows_empty_results`の隣にPHPUnitを1件追加する形でこちらで対応する」との回答を受け、**本書でのシナリオ化は行わず、Laravelチーム側のPHPUnit追加をもって対応完了とする**。追加後、テスト名を下表に反映すること。

| 対応者 | 対応内容 | ステータス |
|---|---|---|
| Laravelチーム | `MatchingControllerTest`に422×他error_codeのテストを追加 | 依頼中 |

---

### LP-CONTRACT-01 応答契約違反への耐性（検証結果：バリデーション追加は不要と結論）

`MatchResult::fromArray()`が`match_rank`のA〜D範囲外・`match_score`の0〜100範囲外・matches件数超過・降順崩れをバリデーションしていない件について、Laravelチームの調査結果を実装コードで検証した。

| # | 懸念点 | 検証結果 |
|---|---|---|
| 1 | rankがA〜D以外 | `laravel/resources/js/Components/Common/RankBadge.tsx`にて`RANK_STYLES[rank] ?? RANK_FALLBACK_STYLE`（バーも同様に`RANK_BAR_STYLES[...] ?? RANK_BAR_FALLBACK_STYLE`）により中立グレーへ自動フォールバックすることを確認。表示崩れなし |
| 2 | scoreが0〜100範囲外 | `laravel/resources/js/Components/Matching/MatchCard.tsx:94`の`style={{ width: `${Math.min(100, Math.max(0, match_score))}%` }}`によりバー幅はクランプされることを確認。バーは崩れない。ただし数値表記（`{match_score} / 100`、96行目付近）はクランプされず生の値がそのまま表示される（例：`150 / 100`）ため、見た目としてはやや不自然になり得る点は補足として記録 |
| 3 | matches件数が5〜6件超過 | Laravel側に上限処理は無いが、Python側（PR #25 `matching_service.py:349`）で`results[:_MAX_RESPONSE_MATCHES]`（`_MAX_RESPONSE_MATCHES = 5`）により送信前に既に5件へ切り詰められていることを確認。上流の契約が保証されている |
| 4 | スコア降順でない | 同じくPython側（`matching_service.py:344`）で`results.sort(key=lambda r: r.match_score, reverse=True)`が送信前に必ず適用されていることを確認。`schemas.py`のコメントにも「スコア降順、常に最大5件（QA#33・QA#50）」と明記されている |

**結論**：4項目すべてについて実害なし、または上流（Python）側で契約として保証済みであることをコードで確認できたため、**`MatchResult::fromArray()`へのバリデーション追加は行わない**。Laravelチームの指摘どおり、ここに例外throwを追加すると「rankが1件想定外なだけで結果一覧が全滅し`flash.error`表示になる」という、現状より悪い挙動（劣化耐性の後退）を招くため、追加しない判断が合理的である。

将来的にPython側の契約が変わる可能性に備え、もし対応する場合は「検証して弾く（例外throw）」ではなく「コントローラの突合直前で`array_slice`と降順ソートを掛けて正規化する」方式が副作用がなく望ましい、というLaravelチームの提案も合わせて記録する。優先度は低く、現時点でのアクションは不要。

---

## 3. 実施結果サマリー（記入用テンプレート）

| シナリオID | 実施日 | 実施者 | 結果（OK/NG/対応済み） | 不具合・Issue番号 | 備考 |
|---|---|---|---|---|---|
| LP-TIMEOUT-01 | | | | | |
| LP-CONTRACT-02 | | | | | PR #25マージ・error_code不整合是正後に実施 |

> LP-ERR-01はLaravelチーム側のPHPUnit追加で対応完了予定のため本テンプレートから除外。LP-CONTRACT-01は調査の結果「対応不要」で完結済みのため実施対象外（§2参照）。

---

## 4. 未確定事項・今後の対応

| 項目 | 内容 | 対応 |
|---|---|---|
| **error_code入れ子構造の不整合（PR #25）** | 404/422/500は`{"detail": {"error_code": ...}}`、504/400は`{"error_code": ...}`とPython内部でも形が割れており、Laravelの`mapErrorResponse()`はトップレベルしか見ないため404/422判定が機能しない | **要早期対応**。PR #25側へ共有し、router側のtry/exceptを外してmain.pyのapp-level handlerに委ねる等の修正を依頼する。是正後にLP-CONTRACT-02を実施 |
| LP-CONTRACT-01（rank/score/件数/順序） | フロントのフォールバック・クランプ実装とPython側のソート・件数上限実装により実害なしと結論 | 対応不要（結論確定） |
| LP-ERR-01（422の他error_code分岐） | Laravelチーム側でPHPUnit追加対応予定 | 追加後、追加されたテスト名を§1表に反映 |
| project_ids指定時の送信内容 | 案件を絞り込んだマッチング実行時に `project_ids` が正しく送信されるかの直接テストは現状なし | 優先度は低いと判断し本書では見送るが、実装変更時は留意 |
| AI要約エンジンのレスポンス形整合 | `python/app/routers/profile.py`（PR #25）も同様にrouter内try/exceptで`HTTPException(detail={"error_code":..., "message":...})`へ変換しており、404（ENGINEER_NOT_FOUND）は同じ入れ子構造になる。ただし`HttpAiSummaryClient::generate()`は`error_code`を一切読まず、`$response->failed()`（4xx/5xx全般）を一律`AiSummaryException::upstream()`として扱う実装のため、**この不整合はAI要約フローの挙動には影響しない**ことをコードで確認済み | 対応不要（確認完了） |
