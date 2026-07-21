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

    public const EMPTY_UNAVAILABLE = 'unavailable';   // マッチはあったが対象案件が削除・非掲出で全滅（#4）

    public function __construct(private readonly MatchingEngineClient $engine) {}

    /**
     * GET /engineers/{engineer}/matching
     * ページロード時にマッチングを同期実行し、結果を表示する（TBD #4：自動実行を採用）。
     */
    public function show(Request $request, Engineer $engineer): Response
    {
        // 対象人材サマリー（ヘッダー表示）は既存 EngineerResource を再利用する
        // （age / available_label 等の算出を二重定義しない）。skills / 担当営業を Eager Load し N+1 を防ぐ。
        $engineer->load(['skills:id,engineer_id,label,detail', 'mainUser:id,name', 'subUser:id,name']);

        ['items' => $results, 'reason' => $emptyReason] = $this->resolveMatches($engineer);

        return Inertia::render('Matching/Show', [
            'engineer' => EngineerResource::make($engineer),
            // data ラップを避けるため toArray で素の配列にする（既存 PipelineController と同方式）。
            'results' => MatchingResource::collection($results)->toArray($request),
            // 結果0件のとき、その理由（no_match / engine_error / unavailable）をフロントへ渡し
            // 空状態の文言・アイコンを出し分ける。結果ありのときは null。
            'emptyReason' => $emptyReason,
        ]);
    }

    /**
     * マッチングエンジンを呼び出し、案件情報・パイプライン状態と突合した結果を理由付きで返す。
     * エラー種別に応じて挙動を分ける（設計書 §4.2）。空状態はさらに reason で細分する。
     *  - NotFound（404 / 非掲出）：404 応答
     *  - NoCandidate（候補0件）：結果0件（reason=no_match）として正常表示
     *  - スコア0件：同上（reason=no_match）
     *  - Upstream（400/500/504・接続不可）：flash.error を出しつつ結果0件（reason=engine_error）で描画（Silent Rejection 回避）
     *  - 突合後全滅：マッチはあったが案件が削除・非掲出で1件も残らない（reason=unavailable）
     *
     * @return array{items: list<array{result: MatchResult, project: Project, is_in_pipeline: bool}>, reason: ?string}
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

            return ['items' => [], 'reason' => self::EMPTY_ENGINE_ERROR];
        }

        if (count($matches) === 0) {
            return ['items' => [], 'reason' => self::EMPTY_NO_MATCH];
        }

        $projectIds = array_map(static fn ($m) => $m->projectId, $matches);

        // 案件情報を一括取得（N+1 回避）。TEXT（description / work_env / remarks）は取得しない。
        $projects = Project::query()
            ->whereIn('id', $projectIds)
            ->with(['projectSkills:id,project_id,skill_type,label'])
            ->get([
                'id', 'name', 'client_name', 'commercial_flow', 'headcount',
                'rate_min', 'rate_max', 'rate_note', 'work_style', 'start_date',
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

        // エンジンのスコア降順を保ったまま突合する。案件が取得できないもの（削除済み等）は除外。
        $items = [];
        foreach ($matches as $match) {
            if (! $projects->has($match->projectId)) {
                continue;
            }

            $items[] = [
                'result' => $match,
                'project' => $projects->get($match->projectId),
                'is_in_pipeline' => $inPipeline->has($match->projectId),
            ];
        }

        // エンジンはマッチを返した（count($matches) > 0）のに突合後に1件も残らない場合は、
        // スコアリング後に対象案件が削除・非掲出になったレース（#4）。no_match（そもそも候補なし）
        // とは区別し、unavailable として専用の空状態を出す。
        if (count($items) === 0) {
            return ['items' => [], 'reason' => self::EMPTY_UNAVAILABLE];
        }

        return ['items' => $items, 'reason' => null];
    }
}
