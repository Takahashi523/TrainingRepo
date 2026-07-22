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
            '*/api/v1/matching/calculate' => Http::response([
                'engineer_id' => 1,
                'generated_at' => now()->toIso8601String(),
                'matches' => $matches,
            ], 200),
        ]);
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

    public function test_upstream_error_shows_flash_error_and_empty_results(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response(['error_code' => 'UPSTREAM_TIMEOUT'], 504)]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
        // #3：通信失敗 → emptyReason = engine_error（flash も併発）
        // flash.error は Inertia の共有プロップとしても同一リクエストで渡ること（トースト表示の前提）
        $response->assertInertia(fn ($page) => $page->has('results', 0)
            ->where('emptyReason', 'engine_error')
            ->where('flash.error', 'マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。'));
    }

    public function test_bare_404_without_error_code_is_treated_as_upstream_not_404(): void
    {
        // エンジン実体が未デプロイでパスが存在しない場合の裸の 404（error_code 無し）。
        // 「人材が存在しない」404 と区別し、上流障害として空状態＋エラー表示にする（404 にしない）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(['*/api/v1/matching/calculate' => Http::response(['detail' => 'Not Found'], 404)]);

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
        $response->assertInertia(fn ($page) => $page->has('results', 0));
    }

    public function test_connection_failure_is_treated_as_upstream(): void
    {
        // エンジン未起動（接続拒否/タイムアウト）→ 上流障害として空状態＋エラー表示（404 にしない）。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertOk();
        $response->assertSessionHas('error');
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
            // フロントの正確なラベル（終了／ペンディング）表示のため status を返す
            ->where('results.1.project.status', 'closed')
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
            ->where('results.0.project.status', 'closed')
            ->where('results.1.is_available', false)
            ->where('results.1.project.status', 'pending')
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
