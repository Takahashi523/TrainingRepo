# Nexus 結合テスト（Laravel-Python）シナリオ

| 項目 | 内容 |
|---|---|
| 対象システム | Nexus（国内SES向け 人材・案件マッチングシステム） |
| ドキュメント種別 | 結合テストシナリオ（V字モデル：狭義の結合テストレベル。Laravel↔Python間の外部インターフェース結合に限定） |
| 対象範囲 | Laravel（`app/Services/Matching/*`, `app/Services/Ai/*`）とPython（マッチング／AI要約エンジン）間のHTTP結合点。画面表示・DB永続化・他機能連携は対象外（該当部分は `Nexus_結合テストシナリオ.md`〔総合テストシナリオ〕を参照） |
| 参照元 | `laravel/app/Services/Matching/HttpMatchingEngineClient.php`／`MatchResult.php`／`MatchingEngineException.php`、`laravel/app/Services/Ai/HttpAiSummaryClient.php`／`AiSummaryResult.php`／`AiSummaryException.php`、既存PHPUnit（`laravel/tests/Feature/MatchingControllerTest.php`／`EngineerControllerTest.php`）、設計書PR#12・QA#48 |
| 作成日 | 2026-08-14 |
| 経緯 | Laravelチームからの提案（「結合テストシナリオ レビュー：スコープと観点の整理について」）を受け、`Nexus_結合テストシナリオ.md` を総合テストシナリオへ位置づけ直した上で、Laravel↔Python間の外部結合を狭く検証する本書を新規に切り出した。既存PHPUnitとの重複を避けるため、提案された5観点は実際のテストコードと突き合わせて再検証し、既にカバー済みの観点（§1）と、真に不足している観点（§2）に整理し直している |
| 改訂 | v1.3（2026-08-18）：本書で「要対応」としていたPython側の不整合2件（error_codeの入れ子構造・職務要約の保存責務の二重化）をPR #25にて修正済み（commit `b221b74`／`38fc451`／`2e09dcc`）。これに伴い§1・LP-CONTRACT-02の事前条件・§4積み残し表のステータスを更新。**500応答のerror_code値の不統一（`INTERNAL_SERVER_ERROR`／`INTERNAL_ERROR`）は今回の修正対象外のため、未対応のまま§4に残している**。<br>v1.2（2026-08-17）：Laravelチームからの再指摘を反映。①§1で「LP-CONTRACT-02で確認する」と宣言していた職務要約（`/api/v1/ai/profile-summary`）の手順が同シナリオに存在しない齟齬を是正し、手順5〜7を追加。②積み残し表のAI要約の行が「対応不要（確認完了）」となっており§1の「未確認」と矛盾していたため、error_code階層の影響（確認完了）と契約適合そのもの（未確認）を分離。③新たに判明した**職務要約の保存責務の二重化**（Python側の`UPDATE engineers`）を追記。<br>v1.1（2026-08-15）：Laravelチームからの回答を受けて全面見直し。①LP-ERR-01はLaravelチーム側でPHPUnit追加により対応されるため本書のアクティブシナリオから除外。②LP-CONTRACT-01はフロントエンド（`RankBadge.tsx`／`MatchCard.tsx`）のフォールバック・クランプ実装とPython側（PR #25）のソート・件数上限実装を確認のうえ「バリデーション追加は不要」で結論づけ。③最重要の指摘として、§1「PHPUnitでカバー済み・参照のみ」の前提そのものを見直し。既存PHPUnitは`Http::fake`によるLaravel自身の想定をなぞっているに過ぎず、実際のPython実装（PR #25）とは**error_codeの入れ子構造が食い違っている**ことをソースコードで確認した（詳細は§1参照）。これを受け、§1の項目は実Pythonに対する契約適合確認シナリオ（LP-CONTRACT-02）に格上げした |

---

## 0. 本書の位置づけと前提

### 0.1 なぜ別紙に切り出したか

Laravelチームの指摘どおり、`Nexus_結合テストシナリオ.md` が扱う「12画面横断・DB状態/Props/フラッシュまで確認」という内容はV字モデルでは総合テスト（システムテスト）レベルにあたる。一方、Laravel↔Pythonという2コンポーネント間の結合点そのものを狭く検証するのが本来の結合テストであり、本書がその役割を担う。

### 0.2 既存PHPUnitとの重複排除方針（v1.1で前提を修正）

初版では、Laravelチームが「追加したい観点」として挙げた5点のうち3点を「PHPUnitで厚くカバー済み・参照のみ」として新規シナリオ化を見送った。しかしLaravelチームからの指摘により、この前提自体に見落としがあったことが判明した。

**問題点**：既存PHPUnit（`Http::fake`）が固定している「想定応答」はLaravel自身が書いたものであり、Pythonが実際にそのとおり返すかどうかは検証していない。証明されているのは「想定が正しければLaravelは正しく振る舞う」までで、想定自体の正しさ（契約のPython側）は別途確認が必要。

**実例（2026-08-17に確認・2026-08-18にPR #25で修正済み）**：Python実装（`python/app/routers/matching.py`）は`EngineerNotFoundError`／`NoActiveCandidateError`をルーター内のtry/exceptで捕捉し、`HTTPException(detail={"error_code": ..., "message": ...})`に変換して**いた**。FastAPIの既定動作によりこれは`{"detail": {"error_code": ..., "message": ...}}`という**入れ子**のJSONとして返る。一方Laravelの`HttpMatchingEngineClient::mapErrorResponse()`は`$body['error_code']`を**トップレベル**で読むため、実際には常に`null`となり、`ENGINEER_NOT_FOUND`／`NO_ACTIVE_PROJECT`の分岐に入らずすべて`upstream()`に落ちていた。`python/app/main.py`には同じ例外に対する正しい形（トップレベル）のapp-level exception handlerが存在するが、router側のtry/exceptが先に捕捉するため到達しない状態（到達不能コード）になっていた。`python/tests/test_routers.py`も404/422を`response.json()["detail"]["error_code"]`とアサートしており、Python側テストがこの入れ子構造をそのまま正として固定してしまっていた。

> **現在の状態**：PR #25（commit `b221b74`）にて、`BedrockError`と同じ「捕捉して`raise`で再送出しapp-levelハンドラに委ねる」パターンへ変更済み。404/422はトップレベル形式で返るようになり、テストのアサーションもトップレベル参照に修正、応答の形自体を固定する契約テスト`test_error_response_body_is_flat_not_nested`を追加した。**なお500応答は今回の対象外で、`{"detail": {"error_code": "INTERNAL_SERVER_ERROR", ...}}`の入れ子形式のまま残っている**（Laravel側は500を一律`upstream()`扱いとするため実挙動への影響はない。§4参照）。実環境での確認はLP-CONTRACT-02の手順1〜4で行う。

このため、この方針を以下のとおり修正した。

- **§1（実Pythonとの契約適合を要確認）**：AI要約フロー・IDのみ送信の契約・error_code分岐は、Laravel側のロジックとしてはPHPUnitでカバー済みだが、Python実装との整合は別途確認が必要。特にerror_code分岐は上記の入れ子構造の不整合が実在したため、テスト以前に**実装修正が必要な不具合**として扱った（2026-08-18にPR #25で修正済み。詳細は§1の表を参照）。
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
| AI要約フロー（正常/504/空出力/appeal_note連動） | `EngineerControllerTest`の該当テスト群でLaravel側のロジックは検証済み | **不整合を2件確認済み**。①error_code階層：`routers/profile.py`も同じ入れ子構造だが、`HttpAiSummaryClient::generate()`は`error_code`を参照せず4xx/5xxを一律`upstream()`扱いとするため**挙動への影響はなし**（OpenAPI宣言との矛盾のみ）。②**保存責務の二重化**：`matching_service.py`の`generate_profile_summary()`がPython側で`UPDATE engineers SET ai_summary...`＋`commit()`を実行しており、設計書v0.6 §1.3「保存責務はLaravel」に反する。Laravel側も`EngineerService::refreshAiSummary()`で同じカラムを書くため二重書き込み状態。フィールド名・型（`ai_summary`／`ai_summary_generated_at`）自体は一致。**②は2026-08-18にPR #25で修正済み（commit `38fc451`：`UPDATE`＋`commit()`を削除し返却のみに変更、「書き込まないこと」を固定する契約テストへ置換）。実エンジンでの確認はLP-CONTRACT-02の手順5〜7で実施する** |
| IDのみ送信の契約 | `test_engine_receives_only_engineer_id` | 送信側（Laravel）の契約であり、Python側との不整合リスクは低い。新規シナリオ化は見送り継続 |
| error_code分岐（404/422） | `test_engine_404_returns_not_found`等。ただし**フェイクが返す想定JSON自体がLaravel側の想定であり未検証** | **不整合を確認済み**。`python/app/routers/matching.py`が`EngineerNotFoundError`／`NoActiveCandidateError`をtry/exceptで捕捉し`HTTPException(detail={"error_code":..., "message":...})`に変換 → FastAPIの既定挙動で`{"detail": {"error_code": ...}}`という**入れ子**で返る。Laravelの`mapErrorResponse()`は`$body['error_code']`を**トップレベル**で読むため常にnullとなり、404/422いずれも判定に失敗して`upstream()`に落ちる。`main.py`の同名例外向けapp-level handler（トップレベルで返す正しい実装）はrouterのtry/exceptに先取りされ到達不能。`python/tests/test_routers.py`は404/422/500を`response.json()["detail"]["error_code"]`、504/400を`response.json()["error_code"]`とアサートしており、Python内でも形が割れたまま固定されている。OpenAPI宣言（`responses={404: {"model": ErrorResponse}}`、`ErrorResponse`はフラット型）とも矛盾。**2026-08-18にPR #25で修正済み（commit `b221b74`）**：`BedrockError`と同じ「捕捉して`raise`で再送出しapp-levelハンドラに委ねる」パターンに揃え、トップレベル形式で返すよう変更。あわせて応答の形自体を固定する契約テスト`test_error_response_body_is_flat_not_nested`を追加（修正前のコードでは4件失敗することを確認済み） |

### LP-CONTRACT-02 実Pythonサービスとの契約適合確認（PR #25マージ後に実施）

| 項目 | 内容 |
|---|---|
| 目的 | 実際に稼働するPythonサービス（PR #25、またはそのマージ後の`develop`）に対し、Laravel側が期待するレスポンス形・**および副作用（DB書き込みの有無）**が契約どおりであることを確認する。フィールド名・error_codeの階層・日時フォーマット・空の表現・保存責務が対象 |
| 対象エンドポイント | **マッチング（`/api/v1/matching/calculate`）＝手順1〜4／職務要約（`/api/v1/ai/profile-summary`）＝手順5〜7**の両方 |
| 事前条件 | **PR #25がマージされていること**。前提となる2件の不整合（①error_codeの入れ子構造、②職務要約の保存責務の二重化）は2026-08-18にPR #25で修正済み（`b221b74`／`38fc451`）だが、`develop`へのマージはレビュー待ちのため、マージ後に実施する |
| 前提の確認 | 本シナリオは単なるテストではなく、上記2件の実装修正が実エンジンで意図どおり効いていることの検証を兼ねる |

**手順（マッチング：`/api/v1/matching/calculate`）**
1. 正常系：人材にマッチング候補がある状態で `/engineers/{id}/matching` を表示し、実エンジンからの応答を確認する。
2. 対象なし（422／NO_ACTIVE_PROJECT相当）：候補案件が0件の人材で実行し、`emptyReason: "no_match"`（エラー扱いにならないこと）を確認する。
3. 対象人材なし（404／ENGINEER_NOT_FOUND相当）：Python側に存在しない`engineer_id`で実行し、404として「対象なし」扱いになることを確認する（上流障害として`engine_error`にならないこと）。
4. 上流障害（Bedrockタイムアウト等504）：Python側で意図的にAI呼び出しを失敗させ、`emptyReason: "engine_error"`・`flash.error`になることを確認する。

**手順（職務要約：`/api/v1/ai/profile-summary`）**

5. 正常系：`appeal_note`を入力した人材を登録し、実Pythonの`/api/v1/ai/profile-summary`が呼ばれ、`ai_summary`／`ai_summary_generated_at`が画面・DBに反映されることを確認する。
6. 空出力：`appeal_note`が空の人材を登録し、200かつ`ai_summary`が空で返り、`ai_summary`がNULL据え置き・エラートーストなしになることを確認する。
7. 保存責務：Laravel側がタイムアウトする状況（`services.ai_summary.timeout`を意図的に短縮する等）を作り、失敗トースト表示後に`engineers.ai_summary`が書き換わっていないことを確認する。

**期待結果**
- 手順1〜4：Laravel側の`mapErrorResponse()`がPythonの実際のレスポンス形（error_codeの階層を含む）を正しく解釈し、意図した`emptyReason`／flashメッセージに分岐する。特に2・3が誤って`engine_error`（上流障害扱い）にならないこと。
- 手順5〜6：`ai_summary`／`ai_summary_generated_at`のフィールド名が一致し、ISO8601形式がLaravel側の`Carbon::parse()`で問題なくパースできること。空出力が失敗扱いにならないこと。
- 手順7：**Laravel側がタイムアウトで打ち切った場合、`engineers.ai_summary`は更新されないこと**（＝Python側のUPDATEが除去され、保存責務がLaravel一本に統一されていること）。

> **手順7の位置づけ**：保存責務の二重化はPR #25（commit `38fc451`）で是正済みのため、本手順は**修正が実環境で効いていることの確認**として実施する。是正前は、Laravelが失敗トーストを出しているにもかかわらずPython側のcommitにより画面には要約が表示される、という食い違いが発生していた（加えてPythonの生SQLは`updated_at`を更新しないため更新時刻の整合も崩れていた）。Python側には`test_does_not_write_to_db_when_summary_is_not_empty`を追加して書き込みの復活を固定しているが、この事象はLaravel側の`Http::fake`では原理的に検出できない（fakeはHTTP応答を差し替えるだけで、Python側のDB副作用が発生しないため）。実エンジンに対する本手順でしか確認できない点に留意する。

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

**結論（`MatchResult::fromArray()`について）**：上記4項目はいずれも表示経路では実害なし、または上流（Python）側で契約として保証済みであることをコードで確認できたため、**`MatchResult::fromArray()`へのバリデーション追加は行わない**。ここに例外throwを追加すると「rankが1件想定外なだけで結果一覧が全滅し`flash.error`表示になる」という、現状より悪い挙動（劣化耐性の後退）を招くため、追加しない判断が合理的である。

**ただし、書き込み経路には実害が残る（本書で追加検証した論点）**：上記の「実害なし」はいずれも**表示経路（マッチング結果画面のレンダリング）**についての評価である。一方、そのカードから「パイプラインに追加」を実行した場合の**書き込み経路**は、`laravel/app/Http/Requests/PipelineStoreRequest.php:52-53`（`develop` 4f87acc 時点）にて以下のとおり厳格に検証している。

```php
'match_score' => ['required', 'integer', 'between:0,100'],
'match_rank'  => ['required', 'in:A,B,C,D'],
```

`match_score`／`match_rank`はマッチング実行時点のスナップショット（QA#45）であり、ユーザーが画面上で入力・修正できる項目ではない。そのためエンジンが`rank="E"`や`score=150`を返した場合、カードは正常に表示される（グレーバッジ・クランプ済みバー）にもかかわらず、追加を実行すると「マッチングランクには、A, B, C, D のいずれかを選択してください。」相当のフィールドエラーがドロワー内に表示され（`failedValidation()`のdocblockにも「上記以外（スコア不正など）も従来どおり back でフィールドエラーをドロワーに表示する」と明記）、**ユーザーには回避手段がなく、その候補を永久に追加できない行き止まりになる**。

すなわち問題の本質はバリデーションの有無ではなく、**表示経路（何でも受け入れる）と書き込み経路（A〜D・0〜100のみ受け入れる）で許容ポリシーが不一致であること**にある。どちらに揃えるかの判断が必要。

| 対応案 | 内容 | 評価 |
|---|---|---|
| 案A：取り込み時に正規化 | `MatchResult::fromArray()`で`match_score`を0〜100にクランプ、`match_rank`がA〜D以外なら`null`に落とす（DBは`match_score`が`unsignedTinyInteger` nullable、`match_rank`が`char(1)` nullableのため、いずれもnull許容） | 例外throwによる「全滅」を招かず、表示・書き込みの両経路が一貫する。`RankBadge`はnullを「—」表示として既に実装済みのため、フロント改修も不要。**推奨** |
| 案B：書き込み経路を緩める | `PipelineStoreRequest`の`in:A,B,C,D`／`between:0,100`を外す | DBに不正値がそのまま入り、以後の集計・表示の前提が崩れる。非推奨 |
| 案C：現状維持 | 何もしない | Python側が契約どおり実装している限り顕在化しないが、契約が崩れたときに原因の分かりにくい行き止まりが発生する |

なお、件数超過・降順崩れ（#3・#4）については、対応するとしても「検証して弾く」のではなく「コントローラの突合直前で`array_slice`と降順ソートを掛けて正規化する」方式が副作用がなく望ましい、というLaravelチームの提案に同意する。こちらは表示順のみに影響し行き止まりを生まないため、優先度は低い。

---

## 3. 実施結果サマリー（記入用テンプレート）

| シナリオID | 実施日 | 実施者 | 結果（OK/NG/対応済み） | 不具合・Issue番号 | 備考 |
|---|---|---|---|---|---|
| LP-TIMEOUT-01 | | | | | |
| LP-CONTRACT-02 手順1〜4（マッチング） | | | | | PR #25マージ・error_code不整合是正後に実施 |
| LP-CONTRACT-02 手順5〜7（職務要約） | | | | | 手順7は保存責務の二重化是正後に実施（是正前は現状記録） |

> LP-ERR-01はLaravelチーム側のPHPUnit追加で対応完了予定のため本テンプレートから除外。LP-CONTRACT-01は調査の結果「対応不要」で完結済みのため実施対象外（§2参照）。

---

## 4. 未確定事項・今後の対応

| 項目 | 内容 | 対応 |
|---|---|---|
| ~~error_code入れ子構造の不整合（PR #25）~~ | 404/422がPython側で`{"detail": {"error_code": ...}}`と入れ子になっており、Laravelの`mapErrorResponse()`はトップレベルしか見ないため判定が機能していなかった | **✅ 対応済み（2026-08-18・PR #25 `b221b74`）**。`BedrockError`と同じ「捕捉して`raise`で再送出しapp-levelハンドラに委ねる」パターンに揃え、契約テストを追加。マージ後にLP-CONTRACT-02の手順1〜4で実環境確認 |
| 500応答のerror_code値の不統一（PR #25） | router側は`INTERNAL_SERVER_ERROR`、`main.py`のapp-levelハンドラは`INTERNAL_ERROR`と値が異なる | **未対応**。`b221b74`では404/422のみを対象とし、500の挙動には手を入れていない。Laravel側は500を一律`upstream()`扱いとするため実挙動への影響はないが、ログ・監視の観点で統一が望ましい。設計書§4.2の正の値を確認のうえ別途対応する |
| LP-CONTRACT-01（rank/score/件数/順序） | 表示経路は実害なし。ただし**書き込み経路（`PipelineStoreRequest`の`in:A,B,C,D`／`between:0,100`）で行き止まりが発生**することを追加検証で確認 | `MatchResult::fromArray()`への例外throw追加は不要（結論維持）。一方、表示経路と書き込み経路の許容ポリシー不一致は要協議。案A（取り込み時の正規化）を推奨 |
| LP-ERR-01（422の他error_code分岐） | Laravelチーム側でPHPUnit追加対応予定 | 追加後、追加されたテスト名を§1表に反映 |
| project_ids指定時の送信内容 | 案件を絞り込んだマッチング実行時に `project_ids` が正しく送信されるかの直接テストは現状なし | 優先度は低いと判断し本書では見送るが、実装変更時は留意 |
| ~~AI要約：error_code階層の影響~~ | `routers/profile.py`も同じ入れ子構造だったが、`HttpAiSummaryClient::generate()`は`error_code`を読まず4xx/5xxを一律`upstream()`扱いとするため、**挙動への影響はなし**。ただしOpenAPI宣言との矛盾は残っていた | **✅ 対応済み（2026-08-18・PR #25 `b221b74`）**。matching.pyと揃えて修正 |
| ~~AI要約：保存責務の二重化（PR #25）~~ | `matching_service.py`の`generate_profile_summary()`がPython側で`UPDATE engineers SET ai_summary...`＋`commit()`を実行し、`EngineerService::refreshAiSummary()`と二重書き込みになっていた。Laravel側が30秒でタイムアウトした後にPythonがcommitすると「失敗トーストが出ているのに画面には要約が表示される」食い違いが発生 | **✅ 対応済み（2026-08-18・PR #25 `38fc451`）**。`UPDATE`＋`commit()`を削除し返却のみに変更。`test_does_not_write_to_db_when_summary_is_not_empty`で書き込みの復活を固定。実環境での是正確認はLP-CONTRACT-02の手順7 |
| AI要約：契約適合そのもの | フィールド名・型は一致確認済み。実エンジンに対する疎通・空出力・保存責務の確認は未実施 | **未確認**。LP-CONTRACT-02の手順5〜7で実施 |
