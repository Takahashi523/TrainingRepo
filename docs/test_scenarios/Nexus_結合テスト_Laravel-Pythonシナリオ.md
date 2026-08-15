## Re: LP-ERR-01・LP-CONTRACT-01・§1の扱いについて

ご回答ありがとうございます。すべてPR #25（`origin/pr/25`）の実コードまで遡って確認しました。3点とも反映しています。

### LP-ERR-01（422のerror_code分岐）→ ご対応をお願いします

`test_no_active_project_shows_empty_results`の隣にPHPUnitを追加いただける件、承知しました。本書側ではアクティブなシナリオとしては扱わず、「Laravelチーム側で対応予定」のステータス行のみ残しています。追加後、テスト名を教えていただければ§1の表に反映します。

### LP-CONTRACT-01（バリデーション追加の見送り）→ 全面的に同意します

ご指摘いただいた4点、コードで裏取りしました。

- rank範囲外：`RankBadge.tsx`の`RANK_STYLES[rank] ?? RANK_FALLBACK_STYLE`（バーも同様）で中立グレーへフォールバック。表示崩れなし、確認できました。
- score範囲外：`MatchCard.tsx:94`の`Math.min(100, Math.max(0, match_score))`でバー幅はクランプ済み。バーは崩れません（ただし`{match_score} / 100`の数値表記自体はクランプされないため、`150 / 100`のような見た目にはなり得ますが、これは軽微な表示上の違和感に留まり実害とは言えないと判断しています）。
- 件数超過・降順崩れ：PR #25の`matching_service.py`で`results.sort(key=lambda r: r.match_score, reverse=True)`→`results[:_MAX_RESPONSE_MATCHES]`（`_MAX_RESPONSE_MATCHES = 5`）が送信前に必ず適用されることを確認しました。上流の契約として保証されている、というご説明のとおりです。

「1件のrank想定外で結果一覧が全滅する」というトレードオフの指摘も納得です。バリデーション追加は見送り、「将来対応するなら`array_slice`＋降順ソートで正規化」という代替案も本書に記録しました。この件はクローズとして扱います。

### §1（PHPUnitカバー済み・参照のみ）→ ご指摘のとおり前提を見直しました。加えて、AI要約側は影響がないことを確認できました

error_codeの入れ子構造の不整合、`python/app/routers/matching.py`と`main.py`を実際に読んで確認しました。

- router内のtry/exceptが`HTTPException(detail={"error_code": ..., "message": ...})`で先に捕まえるため、`main.py`の`EngineerNotFoundError`／`NoActiveCandidateError`向けapp-level handler（トップレベル形式）が未到達コードになっている点、そのとおりでした。
- `test_routers.py`が404/422/500は`response.json()["detail"]["error_code"]`、504/400は`response.json()["error_code"]`とアサートしており、Python側テストがこの不整合をそのまま固定してしまっている点も確認しました。
- `responses={404: {"model": ErrorResponse}}`の宣言（`ErrorResponse`はフラット型）とも矛盾しており、OpenAPIドキュメントとしても不正確になっています。

**追加確認**：AI要約側（`routers/profile.py`）も同じrouter内try/exceptパターンで404がdetail配下に入れ子になりますが、`HttpAiSummaryClient::generate()`はそもそも`error_code`を一切読まず、`$response->failed()`（4xx/5xx全般）を一律`AiSummaryException::upstream()`として扱う実装だったため、**この不整合はAI要約フローの挙動には影響しないこと**を確認できました。影響範囲はマッチング（`/api/v1/matching/calculate`）の404/422分岐に限定されます。

ご提案どおり、§1は「参照のみ・新規シナリオ化見送り」から、実Pythonとの契約適合確認シナリオ（LP-CONTRACT-02、1本に集約）へ格上げしました。実施はPR #25マージ後、かつ入れ子構造の是正後を前提としています。

### お願い

この入れ子構造の不整合は、テスト計画の論点というより**実装バグ**だと考えています。ご提案いただいた「router側のtry/exceptを外し、main.pyのapp-level handlerに委ねる」修正方針に賛成です。PR #25の作者さんへの共有、こちらからも必要であれば同席・補足しますので、進め方についてご指示いただければと思います。

更新した`Nexus_結合テスト_Laravel-Pythonシナリオ.md`を添付します。
