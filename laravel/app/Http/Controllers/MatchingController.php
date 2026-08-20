<?php

namespace App\Http\Controllers;

use App\Http\Resources\EngineerResource;
use App\Http\Resources\MatchingResource;
use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Services\Matching\MatchingEngineClient;
use App\Services\Matching\MatchingEngineException;
use App\Services\Matching\MatchResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * マッチング結果画面（WF_09）。特定人材に対し案件全件をスコアリングした上位5件を表示する。
 *
 * スコアは Python エンジン（スコアリングロジック設計書 §4.2 E1）からオンデマンド取得し、
 * DB 保存しない（QA #45）。データ連携は同設計書 §1.3 のとおり Python が RDS から直接取得するため、
 * 本 Controller は engineer_id を渡し、結果（スコア5点セット）を受け取り、案件情報を DB で突合する役割に徹する。
 */
class MatchingController extends Controller
{
    /** 空状態の理由（フロントで文言・アイコンを出し分ける。null＝結果あり）。 */
    public const EMPTY_NO_MATCH = 'no_match';        // 候補案件なし / スコア0件（#1・#2）

    public const EMPTY_ENGINE_ERROR = 'engine_error'; // エンジン通信失敗（#3・flash.error も併発）

    public const EMPTY_UNAVAILABLE = 'unavailable';   // マッチはあったが対象案件が全てハード削除で全滅（#4）

    /**
     * マッチング実行を許可する人材ステータス。
     *
     * 設計書 §3.4 は「engineers.status='proposable' で絞り込み済みを前提」とするが、面談中
     * （interviewing）は面談が流れる可能性があり並行して別案件を当たる実務ニーズがあるため許可し、
     * 提案不可（not_proposable）のみブロックする（提案不可の人材に AI 採点を走らせない）。
     * 設計書文言との差分は reason.md に記録する。
     */
    private const MATCHABLE_ENGINEER_STATUSES = ['proposable', 'interviewing'];

    public function __construct(private readonly MatchingEngineClient $engine) {}

    /**
     * GET /engineers/{engineer}/matching
     * ページロード時にマッチングを同期実行し、結果を表示する（TBD #4：自動実行を採用）。
     * 画面の「再マッチング」ボタン（#52）も同じ GET を叩く。専用のフラグ・エンドポイントは設けず、
     * 「フラグ無しの GET＝エンジンを実行して最新化する」という既存の意味づけをそのまま使う。
     */
    public function show(Request $request, Engineer $engineer): Response|RedirectResponse
    {
        // 提案不可（not_proposable）の人材はマッチング対象外（設計書 §3.4）。stale ページや直リンク
        // からの実行もサーバー側で弾き、無駄な Python 呼び出し・提案不可人材のパイプライン化を防ぐ。
        // フロントのマッチングボタンも同条件で無効化しているが、ここが最終防波堤となる。
        if (! in_array($engineer->status, self::MATCHABLE_ENGINEER_STATUSES, true)) {
            // 再マッチング（#52）は同じ URL への GET のため、back() の戻り先がこの画面自身になり得る。
            // その場合は同じガードで再び弾かれ、リダイレクトが自分自身へ往復し続けてしまう。
            // 戻り先が現在 URL と同じときだけ人材詳細へ振り替え、ループを断つ（他画面からの流入は従来どおり back）。
            // 判定はパスだけで行う。Referer とリクエストではスキーム・ホストが食い違うことがあり
            // （プロキシ配下など）、URL 文字列の比較だとループ対策が静かに無効化されるため。
            $fallback = route('engineers.show', $engineer);
            $redirect = parse_url(url()->previous(), PHP_URL_PATH) === $request->getPathInfo()
                ? redirect($fallback)
                : back(fallback: $fallback);

            return $redirect->with('error', '提案不可の人材はマッチングを実行できません。');
        }

        // 対象人材サマリー（ヘッダー表示）は既存 EngineerResource を再利用する
        // （age / available_label 等の算出を二重定義しない）。skills / 担当営業を Eager Load し N+1 を防ぐ。
        $engineer->load(['skills:id,engineer_id,label,detail', 'mainUser:id,name', 'subUser:id,name']);

        // パイプライン追加直後の back リダイレクトでは、マッチングエンジンを再実行しない（#4）。
        // スコアは未保存（QA#45）のため再計算すると並びが変わり、コストもかかり、失敗すると成功直後に
        // 空状態へ落ちてしまう。ここではエンジンをスキップし results=null を返す（＝フロントは既存表示を
        // preserveState で保持し、追加カードのみ「追加済み」に楽観更新する）。フラグは1回限り（pull）。
        if (session()->pull('preserve_matching_results', false)) {
            return Inertia::render('Matching/Show', [
                'engineer' => EngineerResource::make($engineer),
                'results' => null,
                'emptyReason' => null,
                // 追加失敗時のみ、試行した案件1件の最新状態を渡す（フロントが該当カードを差分更新する）。
                // 成功時は null（既存の楽観更新 onAdded がカードを「追加済み」にする）。
                'targetState' => session()->pull('pipeline_target_state'),
            ]);
        }

        ['items' => $results, 'reason' => $emptyReason] = $this->resolveMatches($engineer);

        return Inertia::render('Matching/Show', [
            'engineer' => EngineerResource::make($engineer),
            // items=null（エンジン通信失敗）はそのまま results=null として渡し、フロントに既存表示の
            // 据え置きを指示する（#52）。空配列は「本当に0件」なので配列のまま渡し、空状態を表示させる。
            // data ラップを避けるため toArray で素の配列にする（既存 PipelineController と同方式）。
            'results' => $results === null ? null : MatchingResource::collection($results)->toArray($request),
            // 結果0件のとき、その理由（no_match / engine_error / unavailable）をフロントへ渡し
            // 空状態の文言・アイコンを出し分ける。結果ありのときは null。
            'emptyReason' => $emptyReason,
            // 通常ロードでは対象カードの差分更新は不要（追加失敗の back でのみ非 null）。
            'targetState' => null,
        ]);
    }

    /**
     * マッチングエンジンを呼び出し、案件情報・パイプライン状態と突合した結果を理由付きで返す。
     * エラー種別に応じて挙動を分ける（設計書 §4.2）。空状態はさらに reason で細分する。
     *  - NotFound（404 / 非掲出）：404 応答
     *  - NoCandidate（候補0件）：結果0件（reason=no_match）として正常表示
     *  - スコア0件：同上（reason=no_match）
     *  - Upstream（400/500/504・接続不可）：flash.error を出しつつ items=null（reason=engine_error）を返す（Silent Rejection 回避）
     *  - 突合後全滅：マッチはあったが案件が全てハード削除で1件も残らない（reason=unavailable）
     *    ※掲載停止（closed/pending）は残して is_available=false で無効表示するため、ここには該当しない
     *
     * items は「0件」と「スコアを取得できていない」を区別する：
     *  - []   ：スコアリングは成立したが表示できる案件が0件（no_match / unavailable）→ フロントは空状態を表示する
     *  - null ：スコアを取得できていない＝一覧を置き換える中身が無い（engine_error）→ フロントは既存表示を据え置く（#52）
     *
     * @return array{items: ?list<array{result: MatchResult, project: Project, is_in_pipeline: bool, is_available: bool, is_project_full: bool}>, reason: ?string}
     */
    private function resolveMatches(Engineer $engineer): array
    {
        try {
            $matches = $this->engine->calculate($engineer->id);
        } catch (MatchingEngineException $e) {
            if ($e->isNotFound()) {
                abort(404);
            }

            if ($e->isNoCandidate()) {
                return ['items' => [], 'reason' => self::EMPTY_NO_MATCH];
            }

            session()->flash('error', 'マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。');

            // 通信失敗ではスコアを1件も得られていない＝一覧を置き換える中身が無い。空配列（＝0件確定）ではなく
            // null を返し、フロントには既存表示の据え置きを指示する（#52）。これにより、再マッチングを押した結果
            // として手元の有効な一覧が消える事故を防ぐ。初回ロードで失敗したときは据え置く一覧が無いため、
            // フロントは reason=engine_error の空状態を従来どおり表示する。
            return ['items' => null, 'reason' => self::EMPTY_ENGINE_ERROR];
        }

        if (count($matches) === 0) {
            return ['items' => [], 'reason' => self::EMPTY_NO_MATCH];
        }

        $projectIds = array_map(static fn ($m) => $m->projectId, $matches);

        // 案件情報を一括取得（N+1 回避）。TEXT（description / work_env / remarks）は取得しない。
        // status は掲載状態の判定（is_available）に使う。ここでは status で絞り込まず、掲載停止
        // （closed/pending）の案件も残す：スコアリングロジック設計書 §3.4 のとおり追加可能なのは
        // status='open' のみだが、Python 採点後〜この突合の間に別ユーザーが非掲出化したレースでも、
        // ユーザーが注視しているカードを黙って消さず「掲載停止」表示＋追加無効化で見せる
        // （is_in_pipeline と同じ keep+mark+disable 方針）。表示自体できないハード削除案件のみ突合で除外する。
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->with(['projectSkills:id,project_id,skill_type,label'])
            ->get([
                'id', 'name', 'client_name', 'commercial_flow', 'headcount',
                'rate_min', 'rate_max', 'rate_note', 'work_style', 'start_date', 'status',
                'proc_requirements', 'proc_basic_design', 'proc_detail_design',
                'proc_development', 'proc_testing', 'proc_maintenance',
            ])
            ->keyBy('id');

        // 既にパイプライン追加済みの案件IDを1クエリで取得（N+1 回避）。
        $inPipeline = Pipeline::query()
            ->where('engineer_id', $engineer->id)
            ->whereIn('project_id', $projectIds)
            ->pluck('project_id')
            ->flip();

        // 案件ごとの進行中（アクティブ）パイプライン件数（全人材）を1クエリで集計（N+1 回避）。
        // 上限判定は PipelineService@create と同じく進行中のみを数える（QA #50・アクティブ5件。
        // 終了済みは枠を消費しない）。上限到達済みの案件を読み込み時点で「上限到達」として先出し無効化し、
        // クリックして初めて 422 で弾かれる導線（閉じる→再オープンで再度押せてしまう）を避ける。
        $pipelineCounts = Pipeline::query()
            ->whereIn('project_id', $projectIds)
            ->whereIn('status', Pipeline::inProgressValues())
            ->selectRaw('project_id, COUNT(*) as aggregate')
            ->groupBy('project_id')
            ->pluck('aggregate', 'project_id');

        // エンジンのスコア降順を保ったまま突合する。ハード削除された案件は表示できないため除外し、
        // 掲載停止（closed/pending）は is_available=false として残す。
        $items = [];
        foreach ($matches as $match) {
            if (! $projects->has($match->projectId)) {
                continue;
            }

            $project = $projects->get($match->projectId);

            $items[] = [
                'result' => $match,
                'project' => $project,
                'is_in_pipeline' => $inPipeline->has($match->projectId),
                // 追加可能なのは募集中（open）のみ。closed/pending は「掲載停止」表示＋追加無効化にする。
                'is_available' => $project->status === 'open',
                // 上限到達（既存5件）の案件は「上限到達」表示＋追加無効化にする（サーバー enforce の先出し）。
                'is_project_full' => (int) ($pipelineCounts[$match->projectId] ?? 0) >= Pipeline::MAX_PER_PROJECT,
            ];
        }

        // エンジンはマッチを返した（count($matches) > 0）のに突合後に1件も残らない場合は、
        // 対象案件が全てハード削除されたレース（#4）。掲載停止（closed/pending）は残して無効表示する
        // ため、ここには該当しない。no_match（そもそも候補なし）とは区別し unavailable を出す。
        if (count($items) === 0) {
            return ['items' => [], 'reason' => self::EMPTY_UNAVAILABLE];
        }

        return ['items' => $items, 'reason' => null];
    }
}
