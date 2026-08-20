<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\ProjectSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * マッチング結果画面（GET /engineers/{engineer}/matching）。
 * Python エンジンは Http::fake で #12 §4.2 の応答を差し込み、実 Bedrock/GMaps に依存しない。
 */
class MatchingControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------

    /**
     * エンジン成功レスポンス（matches[]）を組み立てる。
     *
     * @param  array<int, array<string, mixed>>  $matches
     */
    private function fakeEngine(array $matches): void
    {
        Http::fake([
            '*/api/v1/matching/calculate' => Http::response($this->engineBody($matches), 200),
        ]);
    }

    /**
     * エンジン成功応答のボディ。再マッチング（#52）のテストでは1テスト内で2回エンジンを呼ぶため、
     * Http::fakeSequence に応答を積むのに本文だけを組み立てられるようにしておく。
     *
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function engineBody(array $matches): array
    {
        return [
            'engineer_id' => 1,
            'generated_at' => now()->toIso8601String(),
            'matches' => $matches,
        ];
    }

    private function match(int $projectId, int $score, string $rank): array
    {
        return [
            'project_id' => $projectId,
            'match_score' => $score,
            'match_rank' => $rank,
            'ai_score_reason' => "理由 {$projectId}",
            'ai_comment' => "推薦 {$projectId}",
            'ai_missing' => null,
        ];
    }

    // -------------------------------------------------------
    // 認可
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $engineer = Engineer::factory()->create();

        $this->get("/engineers/{$engineer->id}/matching")->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_matching_results(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $projects = Project::factory()->count(3)->create(['main_user_id' => $user->id]);

        $this->fakeEngine([
            $this->match($projects[0]->id, 92, 'A'),
            $this->match($projects[1]->id, 61, 'C'),
            $this->match($projects[2]->id, 47, 'D'),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Matching/Show')
            ->where('engineer.id', $engineer->id)
            ->has('results', 3)
            // スコア降順（エンジンの並びを保持）
            ->where('results.0.match_score', 92)
            ->where('results.0.match_rank', 'A')
            ->where('results.2.match_score', 47)
            ->where('results.0.project.id', $projects[0]->id)
            ->where('results.0.is_in_pipeline', false)
            // 募集中（open）案件は追加可能
            ->where('results.0.is_available', true)
            // 上限未到達なので追加可能
            ->where('results.0.is_project_full', false)
            // 結果ありのとき emptyReason は null
            ->where('emptyReason', null)
        );
    }

    public function test_engine_receives_only_engineer_id(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $this->fakeEngine([]);

        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/matching/calculate')
            && $request['engineer_id'] === $engineer->id
            && ! isset($request['project_ids']));
    }

    public function test_required_and_preferred_skills_are_split_by_type(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);
        ProjectSkill::create(['project_id' => $project->id, 'skill_type' => 'required', 'label' => 'PHP', 'detail' => null]);
        ProjectSkill::create(['project_id' => $project->id, 'skill_type' => 'preferred', 'label' => 'Go', 'detail' => null]);

        $this->fakeEngine([$this->match($project->id, 80, 'A')]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertInertia(fn ($page) => $page
            ->where('results.0.project.required_skills.0.label', 'PHP')
            ->where('results.0.project.preferred_skills.0.label', 'Go')
        );
    }

    public function test_is_in_pipeline_is_true_for_already_added_project(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);
        Pipeline::factory()->create(['engineer_id' => $engineer->id, 'project_id' => $project->id]);

        $this->fakeEngine([$this->match($project->id, 88, 'A')]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertInertia(fn ($page) => $page->where('results.0.is_in_pipeline', true));
    }

    public function test_project_at_pipeline_limit_is_marked_full(): void
    {
        // 既存パイプラインが上限（Pipeline::MAX_PER_PROJECT=5件）に達した案件は is_project_full=true。
        // 読み込み時点で「上限到達」として先出し無効化する（クリックして 422 で初めて分かる導線を避ける）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $full = Project::factory()->create(['main_user_id' => $user->id]);
        $room = Project::factory()->create(['main_user_id' => $user->id]);

        // 別人材5件で $full を上限まで埋める（$room は2件で余裕あり）。
        Pipeline::factory()->count(5)->create(['project_id' => $full->id]);
        Pipeline::factory()->count(2)->create(['project_id' => $room->id]);

        $this->fakeEngine([
            $this->match($full->id, 90, 'A'),
            $this->match($room->id, 85, 'A'),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertInertia(fn ($page) => $page->has('results', 2)
            ->where('results.0.project.id', $full->id)
            ->where('results.0.is_project_full', true)
            ->where('results.1.project.id', $room->id)
            ->where('results.1.is_project_full', false));
    }

    public function test_terminal_pipelines_do_not_mark_project_full(): void
    {
        // 上限（is_project_full）は進行中のみで判定。終了済みが5件でも枠は消費しないため full にならない。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);
        Pipeline::factory()->count(5)->terminal()->create(['project_id' => $project->id]);

        $this->fakeEngine([$this->match($project->id, 80, 'A')]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertInertia(fn ($page) => $page->has('results', 1)
            ->where('results.0.is_project_full', false));
    }

    public function test_reload_after_add_skips_engine_and_returns_null_results(): void
    {
        // #4：パイプライン追加直後の back（preserve_matching_results フラグあり）では、
        // マッチングエンジンを再実行せず results=null を返す（フロントは既存表示を保持・楽観更新）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake();

        $response = $this->actingAs($user)
            ->withSession(['preserve_matching_results' => true])
            ->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        // AI エンジンは一度も呼ばれない。
        Http::assertNothingSent();
        $response->assertInertia(fn ($page) => $page->component('Matching/Show')
            ->where('results', null)
            ->where('emptyReason', null)
            // 追加「成功」の back には対象カード差分更新は不要（null）。
            ->where('targetState', null));
    }

    public function test_failed_add_back_passes_target_state_and_skips_engine(): void
    {
        // 追加「失敗」の back：エンジンを再実行せず（results=null）、試行した案件1件の最新状態を
        // targetState として渡す（フロントが該当カードを差分更新し、追加ボタンを無効化する）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $targetState = [
            'project_id' => 123,
            'exists' => true,
            'is_in_pipeline' => true,
            'is_available' => false,
            'is_project_full' => false,
            'status_label' => '終了',
        ];
        Http::fake();

        $response = $this->actingAs($user)
            ->withSession([
                'preserve_matching_results' => true,
                'pipeline_target_state' => $targetState,
            ])
            ->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        Http::assertNothingSent();
        $response->assertInertia(fn ($page) => $page->component('Matching/Show')
            ->where('results', null)
            ->where('targetState.project_id', 123)
            ->where('targetState.is_available', false)
            ->where('targetState.status_label', '終了'));
    }

    // -------------------------------------------------------
    // 再マッチング（#52：ヘッダーの明示操作による再実行）
    // -------------------------------------------------------

    public function test_rerun_without_preserve_flag_runs_engine_again(): void
    {
        // 再マッチングは専用フラグ・専用エンドポイントを持たない素の GET。preserve_matching_results が
        // 無い限りサーバーは毎回エンジンを実行し、最新の結果で一覧を置き換える
        // （リロード / ブラウザバックに頼らず最新化できることの土台）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $first = Project::factory()->create(['main_user_id' => $user->id]);
        $second = Project::factory()->create(['main_user_id' => $user->id]);

        Http::fakeSequence('*/api/v1/matching/calculate')
            ->push($this->engineBody([$this->match($first->id, 90, 'A')]), 200)
            ->push($this->engineBody([$this->match($second->id, 70, 'B')]), 200);

        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching")
            ->assertInertia(fn ($page) => $page->has('results', 1)
                ->where('results.0.project.id', $first->id));

        // 2回目（＝再マッチング）でもエンジンが呼ばれ、結果が差し替わる。
        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching")
            ->assertInertia(fn ($page) => $page->has('results', 1)
                ->where('results.0.project.id', $second->id));

        Http::assertSentCount(2);
    }

    public function test_rerun_reflects_latest_project_state(): void
    {
        // 他担当が案件を停止・削除した／別人材が追加した後でも、再マッチングで最新状態が反映される
        // （掲載停止＝残して無効表示・ハード削除＝一覧から除外・追加済み＝追加ボタン無効化）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $closing = Project::factory()->create(['main_user_id' => $user->id]);
        $deleting = Project::factory()->create(['main_user_id' => $user->id]);
        $adding = Project::factory()->create(['main_user_id' => $user->id]);

        $matches = [
            $this->match($closing->id, 92, 'A'),
            $this->match($deleting->id, 80, 'B'),
            $this->match($adding->id, 70, 'B'),
        ];
        Http::fakeSequence('*/api/v1/matching/calculate')
            ->push($this->engineBody($matches), 200)
            ->push($this->engineBody($matches), 200);

        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching")
            ->assertInertia(fn ($page) => $page->has('results', 3)
                ->where('results.0.is_available', true)
                ->where('results.2.is_in_pipeline', false));

        // 表示中に別ユーザーが行った操作を再現する。
        $closing->update(['status' => 'closed']);
        $deleting->delete();
        Pipeline::factory()->create(['engineer_id' => $engineer->id, 'project_id' => $adding->id]);

        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching")
            ->assertInertia(fn ($page) => $page->has('results', 2)
                // 掲載停止は一覧に残したまま無効表示にする（黙って消さない）。
                ->where('results.0.project.id', $closing->id)
                ->where('results.0.is_available', false)
                // ハード削除された案件は表示できないため一覧から落ちる。
                ->where('results.1.project.id', $adding->id)
                ->where('results.1.is_in_pipeline', true));
    }

    public function test_rerun_engine_failure_returns_null_results_to_preserve_existing_list(): void
    {
        // 再マッチングでエンジンが落ちていたとき、results=[] を返すと「更新を押したら一覧が消えた」に
        // なってしまう。null（＝置き換える中身が無い）を返してフロントに据え置きを指示し、失敗自体は
        // flash.error で伝える（Silent Rejection にはしない）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);

        Http::fakeSequence('*/api/v1/matching/calculate')
            ->push($this->engineBody([$this->match($project->id, 90, 'A')]), 200)
            ->push(['error_code' => 'UPSTREAM_TIMEOUT'], 504);

        // 1回目：通常どおり結果が表示される（＝据え置くべき一覧が手元にある状態）。
        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching")
            ->assertInertia(fn ($page) => $page->has('results', 1));

        // 2回目（再マッチング）：エンジン失敗。results は空配列ではなく null で返る。
        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('results', null)
            ->where('emptyReason', 'engine_error')
            ->where('flash.error', 'マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。'));
    }

    // -------------------------------------------------------
    // 人材ステータスによる実行可否（設計書 §3.4）
    // -------------------------------------------------------

    public function test_not_proposable_engineer_is_blocked_from_matching(): void
    {
        // 提案不可の人材はマッチング実行不可。エンジンを呼ばず back＋flash.error で弾く。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'status' => 'not_proposable',
        ]);
        Http::fake();

        $response = $this->actingAs($user)
            ->from('/engineers')
            ->get("/engineers/{$engineer->id}/matching");

        $response->assertRedirect('/engineers');
        $response->assertSessionHas('error', '提案不可の人材はマッチングを実行できません。');
        // AI エンジンは一度も呼ばれない（無駄な採点を防ぐ）。
        Http::assertNothingSent();
    }

    public function test_not_proposable_engineer_on_rerun_is_redirected_to_engineer_detail(): void
    {
        // 表示中に別タブで提案不可へ変えられた人材で再マッチングを押したケース（#52）。
        // 再マッチングは同じ URL への GET のため、back() のままだと戻り先が自分自身になり
        // 同じガードで再び弾かれ続ける（リダイレクトの自己ループ）。人材詳細へ振り替えて断つ。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create([
            'main_user_id' => $user->id,
            'status' => 'not_proposable',
        ]);
        Http::fake();

        $matchingUrl = "/engineers/{$engineer->id}/matching";
        $response = $this->actingAs($user)->from($matchingUrl)->get($matchingUrl);

        // マッチング画面自身へは戻さない（戻すとループする）。
        $response->assertRedirect("/engineers/{$engineer->id}");
        $response->assertSessionHas('error', '提案不可の人材はマッチングを実行できません。');
        Http::assertNothingSent();
    }

    public function test_proposable_and_interviewing_engineers_can_run_matching(): void
    {
        $user = User::factory()->create();
        $this->fakeEngine([]);

        foreach (['proposable', 'interviewing'] as $status) {
            $engineer = Engineer::factory()->create(['main_user_id' => $user->id, 'status' => $status]);

            $this->actingAs($user)
                ->get("/engineers/{$engineer->id}/matching")
                ->assertOk();
        }
    }

    // -------------------------------------------------------
    // 異常系
    // -------------------------------------------------------

    public function test_non_existent_engineer_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/engineers/99999/matching')->assertNotFound();
    }

    public function test_engine_404_returns_not_found(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response(['error_code' => 'ENGINEER_NOT_FOUND'], 404)]);

        $this->actingAs($user)->get("/engineers/{$engineer->id}/matching")->assertNotFound();
    }

    public function test_no_active_project_shows_empty_results(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response(['error_code' => 'NO_ACTIVE_PROJECT'], 422)]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        // #1：候補案件なし → emptyReason = no_match（エラーではないので flash なし）
        $response->assertSessionMissing('error');
        $response->assertInertia(fn ($page) => $page->component('Matching/Show')
            ->has('results', 0)
            ->where('emptyReason', 'no_match'));
    }

    public function test_engine_returns_empty_matches_shows_no_match(): void
    {
        // #2：エンジンは 200 で成功したが matches が空配列（候補0件と同じ扱い）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $this->fakeEngine([]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionMissing('error');
        $response->assertInertia(fn ($page) => $page->has('results', 0)
            ->where('emptyReason', 'no_match'));
    }

    public function test_upstream_error_shows_flash_error_and_returns_null_results(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response(['error_code' => 'UPSTREAM_TIMEOUT'], 504)]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
        // #3：通信失敗 → emptyReason = engine_error（flash も併発）
        // results は空配列ではなく null（＝一覧を置き換える中身が無い）。再マッチング時にフロントが
        // 既存表示を据え置くための指示であり、[] を返すと押した結果として一覧が消えてしまう（#52）。
        // flash.error は Inertia の共有プロップとしても同一リクエストで渡ること（トースト表示の前提）
        $response->assertInertia(fn ($page) => $page->where('results', null)
            ->where('emptyReason', 'engine_error')
            ->where('flash.error', 'マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。'));
    }

    public function test_bare_404_without_error_code_is_treated_as_upstream_not_404(): void
    {
        // エンジン実体が未デプロイでパスが存在しない場合の裸の 404（error_code 無し）。
        // 「人材が存在しない」404 と区別し、上流障害（results=null＋エラー表示）として扱う（404 にしない）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response(['detail' => 'Not Found'], 404)]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
        $response->assertInertia(fn ($page) => $page->where('results', null));
    }

    public function test_connection_failure_is_treated_as_upstream(): void
    {
        // エンジン未起動（接続拒否/タイムアウト）→ 上流障害（results=null＋エラー表示）として扱う（404 にしない）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
        $response->assertInertia(fn ($page) => $page->where('results', null)
            ->where('emptyReason', 'engine_error'));
    }

    public function test_malformed_engine_response_is_treated_as_upstream(): void
    {
        // 200 だが matches[] の必須キー（project_id）が欠落した不正応答。(int) キャストで 0 に潰して
        // 突合で静かに脱落させず、上流障害（results=null＋flash.error）にする（Silent Rejection 回避）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response([
            'engineer_id' => $engineer->id,
            'generated_at' => now()->toIso8601String(),
            'matches' => [
                ['match_score' => 90, 'match_rank' => 'A'], // project_id 欠落
            ],
        ], 200)]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
        $response->assertInertia(fn ($page) => $page->where('results', null)
            ->where('emptyReason', 'engine_error'));
    }

    public function test_deleted_project_is_excluded_from_results(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);

        // 存在する案件1件＋存在しない案件ID を返す → 存在しない方は突合で除外される
        $this->fakeEngine([
            $this->match($project->id, 90, 'A'),
            $this->match(99999, 85, 'A'),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        // 一部だけ残る場合は結果あり＝emptyReason は null
        $response->assertInertia(fn ($page) => $page->has('results', 1)
            ->where('results.0.project.id', $project->id)
            ->where('emptyReason', null));
    }

    public function test_closed_project_is_kept_but_marked_unavailable(): void
    {
        // 設計書 §3.4：追加可能なのは status='open' のみ。ただし Python 採点後〜表示の間に closed 化
        // した案件は黙って消さず「募集終了」（is_available=false）として残す（is_in_pipeline と同方針）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $open = Project::factory()->create(['main_user_id' => $user->id, 'status' => 'open']);
        $closed = Project::factory()->create(['main_user_id' => $user->id, 'status' => 'closed']);

        $this->fakeEngine([
            $this->match($open->id, 90, 'A'),
            $this->match($closed->id, 85, 'A'),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        // 両方表示され、open は追加可能・closed は追加不可フラグ。emptyReason は null。
        $response->assertInertia(fn ($page) => $page->has('results', 2)
            ->where('results.0.project.id', $open->id)
            ->where('results.0.is_available', true)
            ->where('results.1.project.id', $closed->id)
            ->where('results.1.is_available', false)
            // フロントの正確な表示のため掲載状態ラベルをサーバー解決して返す（closed→終了）
            ->where('results.1.project.status_label', '終了')
            ->where('emptyReason', null));
    }

    public function test_all_closed_projects_are_kept_and_marked_unavailable(): void
    {
        // 全件 closed / pending でも空状態にはせず、募集終了（is_available=false）として一覧に残す。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $closed = Project::factory()->create(['main_user_id' => $user->id, 'status' => 'closed']);
        $pending = Project::factory()->create(['main_user_id' => $user->id, 'status' => 'pending']);

        $this->fakeEngine([
            $this->match($closed->id, 90, 'A'),
            $this->match($pending->id, 85, 'A'),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('results', 2)
            ->where('results.0.is_available', false)
            ->where('results.0.project.status_label', '終了')
            ->where('results.1.is_available', false)
            ->where('results.1.project.status_label', 'ペンディング')
            ->where('emptyReason', null));
    }

    public function test_all_matched_projects_hard_deleted_shows_unavailable_reason(): void
    {
        // #4：エンジンはマッチを返したが、対象案件が全てハード削除で突合できない（レース）。
        // no_match（そもそも候補なし）と区別し emptyReason = unavailable。エラーではないので flash なし。
        // （募集終了 closed/pending は残して無効表示するため、この分岐には該当しない）
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);

        $this->fakeEngine([
            $this->match(99998, 90, 'A'),
            $this->match(99999, 85, 'A'),
        ]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionMissing('error');
        $response->assertInertia(fn ($page) => $page->has('results', 0)
            ->where('emptyReason', 'unavailable'));
    }
}
