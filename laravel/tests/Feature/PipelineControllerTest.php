<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // フロントページ（Pages/Pipelines/*）はフェーズ5で作成・ビルドされるため、
        // バックエンド段階では Vite マニフェスト解決を無効化する（Inertia の Props 検証には不要）。
        $this->withoutVite();
    }

    // -------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------

    /**
     * 指定ユーザーがメイン担当の人材に紐づくパイプラインを作成する。
     */
    private function makePipeline(User $mainUser, array $attributes = [], ?User $subUser = null): Pipeline
    {
        $engineer = Engineer::factory()->create([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $subUser?->id,
        ]);
        $project = Project::factory()->create(['main_user_id' => $mainUser->id]);

        return Pipeline::factory()->create(array_merge([
            'engineer_id' => $engineer->id,
            'project_id' => $project->id,
        ], $attributes));
    }

    // -------------------------------------------------------
    // index: GET /pipelines — 認可
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_index(): void
    {
        $this->get('/pipelines')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_index_with_default_props(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/pipelines');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pipelines/Index', false)
            ->has('columns', 4)
            ->has('filters')
            ->has('users')
            ->has('ranks', 4)
            // ランク選択肢はドロップダウン併記用のスコア範囲（range）を含む（WF_10 準拠）
            ->where('ranks.0.value', 'A')
            ->where('ranks.0.range', '80点以上')
            ->has('statuses')
            ->where('selectedPipeline', null)
            ->has('statusOptions')
        );
    }

    // -------------------------------------------------------
    // index: 担当絞り込み（QA #70）
    // -------------------------------------------------------

    public function test_index_defaults_to_my_pipelines_and_all_shows_everyone(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->makePipeline($me, ['status' => 'proposed']);            // main = me
        $this->makePipeline($other, ['status' => 'proposed'], $me);    // sub = me
        $this->makePipeline($other, ['status' => 'proposed']);         // 無関係

        // デフォルト（未指定）：自分がメイン/サブ担当の2件のみ（QA #70）
        $this->actingAs($me)->get('/pipelines')->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 2)
            ->where('filters.user_id', null)
        );

        // user_id=all（全員）：担当に関わらず全3件
        $this->actingAs($me)->get('/pipelines?user_id=all')->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 3)
            ->where('filters.user_id', 'all')
        );
    }

    public function test_index_filters_by_user_id_uses_main_user_only(): void
    {
        $me = User::factory()->create();
        $target = User::factory()->create();

        $this->makePipeline($target, ['status' => 'proposed']);            // main = target
        $this->makePipeline($me, ['status' => 'proposed'], $target);       // sub = target（除外される）

        $response = $this->actingAs($me)->get('/pipelines?user_id='.$target->id);

        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 1)
            ->where('filters.user_id', $target->id)
        );
    }

    // -------------------------------------------------------
    // index: フィルタ
    // -------------------------------------------------------

    public function test_index_excludes_terminal_statuses(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed']);
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);

        $response = $this->actingAs($me)->get('/pipelines');

        // 進行中の1件のみカウントされ、終了は含まれない
        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 1)
            ->where('columns.1.count', 0)
            ->where('columns.2.count', 0)
            ->where('columns.3.count', 0)
        );
    }

    public function test_index_filters_by_status(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed']);         // entry（旧 applying_before）
        $this->makePipeline($me, ['status' => 'first_waiting']);    // first_interview

        $response = $this->actingAs($me)->get('/pipelines?status[]=first_waiting');

        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 0)
            ->where('columns.1.count', 1)
            ->where('columns.1.cards.0.status', 'first_waiting')
        );
    }

    public function test_index_filters_by_rank(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed', 'match_rank' => 'A']);
        $this->makePipeline($me, ['status' => 'proposed', 'match_rank' => 'C']);

        $response = $this->actingAs($me)->get('/pipelines?rank[]=A');

        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 1)
            ->where('columns.0.cards.0.match_rank', 'A')
        );
    }

    public function test_index_filters_by_keyword_on_engineer_name(): void
    {
        $me = User::factory()->create();
        $eng = Engineer::factory()->create(['main_user_id' => $me->id, 'name' => '田中太郎']);
        $prj = Project::factory()->create(['main_user_id' => $me->id]);
        Pipeline::factory()->create(['engineer_id' => $eng->id, 'project_id' => $prj->id, 'status' => 'proposed']);
        $this->makePipeline($me, ['status' => 'proposed']); // 別の人材

        $response = $this->actingAs($me)->get('/pipelines?keyword=田中');

        $response->assertInertia(fn ($page) => $page->where('columns.0.count', 1));
    }

    public function test_index_filters_by_keyword_on_project_name(): void
    {
        $me = User::factory()->create();
        $eng = Engineer::factory()->create(['main_user_id' => $me->id]);
        $prj = Project::factory()->create(['main_user_id' => $me->id, 'name' => '特別プロジェクトX']);
        Pipeline::factory()->create(['engineer_id' => $eng->id, 'project_id' => $prj->id, 'status' => 'proposed']);
        $this->makePipeline($me, ['status' => 'proposed']); // 別案件

        $response = $this->actingAs($me)->get('/pipelines?keyword=特別プロジェクトX');

        $response->assertInertia(fn ($page) => $page->where('columns.0.count', 1));
    }

    public function test_index_filters_by_status_multiple_or(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed']);         // entry
        $this->makePipeline($me, ['status' => 'first_waiting']);    // first_interview
        $this->makePipeline($me, ['status' => 'final_waiting']);    // final_interview（除外される）

        // proposed OR first_waiting の複数選択で両方ヒットすること（OR 条件）
        $response = $this->actingAs($me)->get('/pipelines?status[]=proposed&status[]=first_waiting');

        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.count', 1)   // entry: proposed
            ->where('columns.1.count', 1)   // first_interview: first_waiting
            ->where('columns.2.count', 0)   // final_interview: 除外
        );
    }

    public function test_index_keyword_matches_engineer_name_or_project_name(): void
    {
        $me = User::factory()->create();

        // 人材Aは氏名でヒット（案件名は無関係）
        $engA = Engineer::factory()->create(['main_user_id' => $me->id, 'name' => 'ヒット太郎']);
        $prjA = Project::factory()->create(['main_user_id' => $me->id, 'name' => '無関係案件アルファ']);
        Pipeline::factory()->create(['engineer_id' => $engA->id, 'project_id' => $prjA->id, 'status' => 'proposed']);

        // 人材Bは案件名でヒット（氏名は無関係）
        $engB = Engineer::factory()->create(['main_user_id' => $me->id, 'name' => '無関係花子']);
        $prjB = Project::factory()->create(['main_user_id' => $me->id, 'name' => 'ヒット案件ベータ']);
        Pipeline::factory()->create(['engineer_id' => $engB->id, 'project_id' => $prjB->id, 'status' => 'proposed']);

        // 氏名一致(A)・案件名一致(B)が OR で両方返る（entry 列に2件）
        $response = $this->actingAs($me)->get('/pipelines?keyword='.urlencode('ヒット'));

        $response->assertInertia(fn ($page) => $page->where('columns.0.count', 2));
    }

    // -------------------------------------------------------
    // index: ソート（null 末尾）
    // -------------------------------------------------------

    public function test_index_sort_rejects_invalid_sort_order_pair(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed', 'match_score' => 90]);
        $this->makePipeline($me, ['status' => 'proposed', 'match_score' => 60]);

        // match_score は desc のみ許可。asc は UI に無い仕様外ペアのためデフォルト（next_action_date asc）へフォールバックする。
        $response = $this->actingAs($me)->get('/pipelines?sort=match_score&order=asc');

        $response->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'next_action_date')
            ->where('filters.order', 'asc')
        );
    }

    public function test_index_sort_by_next_action_date_places_nulls_last(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed', 'next_action_date' => null, 'match_rank' => 'D']);
        $this->makePipeline($me, ['status' => 'proposed', 'next_action_date' => '2026-08-01', 'match_rank' => 'B']);
        $this->makePipeline($me, ['status' => 'proposed', 'next_action_date' => '2026-07-10', 'match_rank' => 'A']);

        $response = $this->actingAs($me)->get('/pipelines?sort=next_action_date&order=asc');

        // 近い順：2026-07-10 → 2026-08-01 → null（末尾）
        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.cards.0.next_action_date', '2026-07-10')
            ->where('columns.0.cards.1.next_action_date', '2026-08-01')
            ->where('columns.0.cards.2.next_action_date', null)
        );
    }

    // -------------------------------------------------------
    // index: TEXT 非露出
    // -------------------------------------------------------

    public function test_index_cards_do_not_expose_text_columns(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, [
            'status' => 'proposed',
            'ai_score_reason' => '露出してはいけない',
            'ai_comment' => '露出してはいけない',
            'ai_missing' => '露出してはいけない',
            'client_comment' => '露出してはいけない',
            'ng_reason' => '露出してはいけない',
        ]);

        $response = $this->actingAs($me)->get('/pipelines');

        $response->assertInertia(fn ($page) => $page
            ->missing('columns.0.cards.0.ai_score_reason')
            ->missing('columns.0.cards.0.ai_comment')
            ->missing('columns.0.cards.0.ai_missing')
            ->missing('columns.0.cards.0.client_comment')
            ->missing('columns.0.cards.0.ng_reason')
            ->where('columns.0.cards.0.status', 'proposed')
        );
    }

    // -------------------------------------------------------
    // index: 4グループ分類
    // -------------------------------------------------------

    public function test_index_classifies_cards_into_four_kanban_groups(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed']);          // entry（旧 applying_before）
        $this->makePipeline($me, ['status' => 'first_scheduling']);  // first_interview
        $this->makePipeline($me, ['status' => 'final_waiting']);     // final_interview
        $this->makePipeline($me, ['status' => 'offered']);           // offer
        $this->makePipeline($me, ['status' => 'contracted']);        // offer

        $response = $this->actingAs($me)->get('/pipelines');

        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.key', 'entry')
            ->where('columns.0.count', 1)
            ->where('columns.1.key', 'first_interview')
            ->where('columns.1.count', 1)
            ->where('columns.2.key', 'final_interview')
            ->where('columns.2.count', 1)
            ->where('columns.3.key', 'offer')
            ->where('columns.3.count', 2)
        );
    }

    // -------------------------------------------------------
    // completed: GET /pipelines/completed
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_completed(): void
    {
        $this->get('/pipelines/completed')->assertRedirect('/login');
    }

    public function test_completed_shows_only_terminal_statuses(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed']);
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);
        $this->makePipeline($me, ['status' => 'declined', 'ended_at' => now()]);

        $response = $this->actingAs($me)->get('/pipelines/completed');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pipelines/Completed', false)
            ->has('pipelines.data', 2)
            ->has('pipelines.meta')
            ->has('filters')
            ->has('users')
            ->has('statuses', 4)
        );
    }

    public function test_completed_shows_all_users_by_default(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);
        $this->makePipeline($other, ['status' => 'closed', 'ended_at' => now()]);

        // 完了済みは初期値が全員（進行中と異なる）
        $response = $this->actingAs($me)->get('/pipelines/completed');

        $response->assertInertia(fn ($page) => $page->has('pipelines.data', 2));
    }

    public function test_completed_filters_by_status(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);
        $this->makePipeline($me, ['status' => 'closed', 'ended_at' => now()]);

        $response = $this->actingAs($me)->get('/pipelines/completed?status[]=rejected');

        $response->assertInertia(fn ($page) => $page
            ->has('pipelines.data', 1)
            ->where('pipelines.data.0.status', 'rejected')
        );
    }

    public function test_completed_filters_by_user_id(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);
        $this->makePipeline($other, ['status' => 'closed', 'ended_at' => now()]);

        $response = $this->actingAs($me)->get('/pipelines/completed?user_id='.$me->id);

        $response->assertInertia(fn ($page) => $page->has('pipelines.data', 1));
    }

    public function test_completed_filters_by_ended_date_range(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => '2026-06-01 10:00:00']);
        $this->makePipeline($me, ['status' => 'closed', 'ended_at' => '2026-06-20 10:00:00']);

        $response = $this->actingAs($me)
            ->get('/pipelines/completed?ended_from=2026-06-10&ended_to=2026-06-30');

        $response->assertInertia(fn ($page) => $page
            ->has('pipelines.data', 1)
            ->where('pipelines.data.0.status', 'closed')
        );
    }

    public function test_completed_default_sort_is_ended_at_desc(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => '2026-06-01 10:00:00']);
        $this->makePipeline($me, ['status' => 'closed', 'ended_at' => '2026-06-20 10:00:00']);

        $response = $this->actingAs($me)->get('/pipelines/completed');

        // 新しい順（ended_at desc）：2026-06-20 が先頭
        $response->assertInertia(fn ($page) => $page
            ->where('pipelines.data.0.status', 'closed')
            ->where('pipelines.data.1.status', 'rejected')
        );
    }

    public function test_completed_does_not_expose_client_comment(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, [
            'status' => 'rejected',
            'ended_at' => now(),
            'ng_reason' => 'NG理由は表示される',
            'client_comment' => '顧客コメントは表示されない',
        ]);

        $response = $this->actingAs($me)->get('/pipelines/completed');

        $response->assertInertia(fn ($page) => $page
            ->where('pipelines.data.0.ng_reason', 'NG理由は表示される')
            ->missing('pipelines.data.0.client_comment')
            ->missing('pipelines.data.0.ai_score_reason')
        );
    }

    public function test_completed_rejects_invalid_ended_date_format(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);

        // 不正な日付文字列は 422（バリデーション設計書 §6：nullable / date）
        $this->actingAs($me)->get('/pipelines/completed?ended_from=not-a-date')
            ->assertSessionHasErrors('ended_from');
        $this->actingAs($me)->get('/pipelines/completed?ended_to=2026-13-99')
            ->assertSessionHasErrors('ended_to');
    }

    public function test_completed_rejects_ended_to_before_ended_from(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);

        // 終了 < 開始 は設計書のメッセージ例どおりのエラー文で拒否される
        $this->actingAs($me)
            ->get('/pipelines/completed?ended_from=2026-06-30&ended_to=2026-06-01')
            ->assertInvalid(['ended_to' => '開始日以降の日付を入力してください']);
    }

    public function test_completed_accepts_same_day_range_and_ended_to_alone(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => '2026-06-15 10:00:00']);

        // 境界値：開始＝終了の同日指定は許可（after_or_equal）
        $this->actingAs($me)
            ->get('/pipelines/completed?ended_from=2026-06-15&ended_to=2026-06-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('pipelines.data', 1));

        // ended_to 単独指定でも after_or_equal が誤発火しない（比較先が空のケース）
        $this->actingAs($me)
            ->get('/pipelines/completed?ended_to=2026-06-30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('pipelines.data', 1));
    }

    public function test_completed_keyword_max_is_100(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()]);

        // 境界値：100文字は許可、101文字は 422
        $this->actingAs($me)
            ->get('/pipelines/completed?keyword='.urlencode(str_repeat('あ', 100)))
            ->assertOk();
        $this->actingAs($me)
            ->get('/pipelines/completed?keyword='.urlencode(str_repeat('あ', 101)))
            ->assertInvalid(['keyword' => 'キーワード']);
    }

    // -------------------------------------------------------
    // show: GET /pipelines/{id}
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login_from_show(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'proposed']);

        $this->get('/pipelines/'.$pipeline->id)->assertRedirect('/login');
    }

    public function test_show_returns_selected_pipeline_with_text_columns(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, [
            'status' => 'proposed',
            'ai_score_reason' => 'AIスコア理由',
            'ai_comment' => 'AIコメント',
            'ai_missing' => '不足条件',
            'client_comment' => '顧客コメント',
        ]);

        $response = $this->actingAs($me)->get('/pipelines/'.$pipeline->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pipelines/Index', false)
            ->where('selectedPipeline.id', $pipeline->id)
            ->where('selectedPipeline.ai_score_reason', 'AIスコア理由')
            ->where('selectedPipeline.ai_comment', 'AIコメント')
            ->where('selectedPipeline.ai_missing', '不足条件')
            ->where('selectedPipeline.client_comment', '顧客コメント')
            ->has('statusOptions', 16)
        );
    }

    public function test_show_status_options_include_is_terminal_flag(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $response = $this->actingAs($me)->get('/pipelines/'.$pipeline->id);

        $response->assertInertia(fn ($page) => $page
            ->where('statusOptions.0.value', 'proposed')
            ->where('statusOptions.0.is_terminal', false)
            ->where('statusOptions.12.value', 'rejected')
            ->where('statusOptions.12.is_terminal', true)
        );
    }

    public function test_show_returns_404_for_non_existent_pipeline(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me)->get('/pipelines/99999')->assertNotFound();
    }

    // -------------------------------------------------------
    // update: PATCH /pipelines/{id} — 正常系
    // -------------------------------------------------------

    public function test_guest_cannot_update_pipeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'proposed']);

        $this->patch('/pipelines/'.$pipeline->id, ['status' => 'rejected'])
            ->assertRedirect('/login');
        $this->assertSame('proposed', $pipeline->fresh()->status);
    }

    public function test_update_ignores_explicit_null_status(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'first_waiting']);

        // status は設計書上 nullable。明示的な null は「ステータス変更なし」として無視され、
        // 他項目の更新は成功する（従来は isTerminal(null) の TypeError で 500 になっていた）。
        $response = $this->actingAs($me)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'status' => null,
            'client_comment' => 'コメントのみ更新',
            'version' => 0,
        ]);

        $response->assertRedirect(route('pipelines.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pipelines', [
            'id' => $pipeline->id,
            'status' => 'first_waiting',
            'client_comment' => 'コメントのみ更新',
        ]);
    }

    public function test_update_persists_management_fields(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $response = $this->actingAs($me)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'client_comment' => '顧客からの返信あり',
            'ng_reason' => null,
            'next_action_date' => '2026-08-15',
            'version' => 0,
        ]);

        $response->assertRedirect(route('pipelines.index'));
        $response->assertSessionHas('success', 'パイプラインを更新しました。');
        $this->assertDatabaseHas('pipelines', [
            'id' => $pipeline->id,
            'client_comment' => '顧客からの返信あり',
            'next_action_date' => '2026-08-15 00:00:00',
        ]);
    }

    public function test_update_records_ended_at_when_transitioning_to_terminal(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'first_waiting', 'ended_at' => null]);

        $this->actingAs($me)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'status' => 'rejected',
            'version' => 0,
        ])->assertRedirect(route('pipelines.index'));

        $pipeline->refresh();
        $this->assertSame('rejected', $pipeline->status);
        $this->assertNotNull($pipeline->ended_at);

        // 進行中カンバンから消え、完了済みに現れる
        $this->actingAs($me)->get('/pipelines')
            ->assertInertia(fn ($page) => $page->where('columns.1.count', 0));
        $this->actingAs($me)->get('/pipelines/completed')
            ->assertInertia(fn ($page) => $page->has('pipelines.data', 1));
    }

    public function test_update_allows_moving_between_in_progress_statuses_without_ended_at(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed', 'ended_at' => null]);

        $this->actingAs($me)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'status' => 'final_waiting',
            'version' => 0,
        ])->assertRedirect(route('pipelines.index'));

        $pipeline->refresh();
        $this->assertSame('final_waiting', $pipeline->status);
        $this->assertNull($pipeline->ended_at);
    }

    // -------------------------------------------------------
    // update: 異常系（終了ロックガード QA #64）
    // -------------------------------------------------------

    public function test_update_rejects_terminal_to_in_progress(): void
    {
        $me = User::factory()->create();
        $endedAt = now()->subDay();
        $pipeline = $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => $endedAt]);

        $response = $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'status' => 'proposed',
            'version' => 0,
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('rejected', $pipeline->fresh()->status);
    }

    public function test_update_rejects_terminal_to_another_terminal(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'rejected', 'ended_at' => now()->subDay()]);

        $response = $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'status' => 'closed',
            'version' => 0,
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertSame('rejected', $pipeline->fresh()->status);
    }

    public function test_update_rejects_management_fields_on_terminal_pipeline(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, [
            'status' => 'rejected',
            'ended_at' => now()->subDay(),
            'ng_reason' => '元のNG理由',
        ]);

        // 終了済みは管理情報（NG理由・次回アクション日）の更新も不可。
        $response = $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'ng_reason' => '終了後に追記しようとしたメモ',
            'next_action_date' => '2026-09-01',
            'version' => 0,
        ]);

        $response->assertSessionHasErrors('status');
        // DB は変更されていない（送信した新しい値が反映されていない）
        $this->assertDatabaseHas('pipelines', [
            'id' => $pipeline->id,
            'ng_reason' => '元のNG理由',
        ]);
    }

    /**
     * ドロワー表示中（referer が show URL /pipelines/{id}）にステータス更新しても、
     * 常に進行中カンバン（index）へ戻す。show へ戻るとドロワーが残る／開く不具合になるため。
     * 絞り込み条件（referer のクエリ）は index に引き継ぐ。
     */
    public function test_update_redirects_to_index_not_show_even_when_referer_is_show(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $response = $this->actingAs($me)
            ->from('/pipelines/'.$pipeline->id.'?keyword=foo')
            ->patch('/pipelines/'.$pipeline->id, ['status' => 'rejected', 'version' => 0]);

        // show URL ではなく index パスへ戻り、フィルタ（keyword）は保持される
        $response->assertRedirect(route('pipelines.index', ['keyword' => 'foo']));
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id, 'status' => 'rejected']);
        $this->assertNotNull($pipeline->fresh()->ended_at);
    }

    public function test_update_rejects_invalid_status(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'status' => 'not_a_real_status',
            'version' => 0,
        ])->assertSessionHasErrors('status');
    }

    public function test_update_rejects_invalid_date(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'next_action_date' => 'not-a-date',
            'version' => 0,
        ])->assertSessionHasErrors('next_action_date');
    }

    public function test_update_rejects_client_comment_over_max(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        // エラー文の項目名が日本語（案件側 ProjectRequest と同方式）であること。
        // assertInvalid は部分一致判定のため、項目名だけを検証できる。
        $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'client_comment' => str_repeat('あ', 1001),
            'version' => 0,
        ])->assertInvalid(['client_comment' => '顧客コメント']);
    }

    public function test_update_rejects_ng_reason_over_max(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'ng_reason' => str_repeat('あ', 1001),
            'version' => 0,
        ])->assertInvalid(['ng_reason' => 'NG理由']);
    }

    public function test_update_returns_404_for_non_existent_pipeline(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me)->patch('/pipelines/99999', ['status' => 'proposed'])
            ->assertNotFound();
    }

    // -------------------------------------------------------
    // update: PATCH /pipelines/{pipeline} — 楽観ロック（version, issue #45）
    // -------------------------------------------------------

    public function test_update_increments_version_when_version_matches(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        // Pipeline::factory()->create() は DB 側の DEFAULT (0) を明示的に返さないため、
        // モデル属性ではなく DB を見て前提（初期 version=0）を確認する。
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id, 'version' => 0]);

        $response = $this->actingAs($me)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'client_comment' => '新しいコメント',
            'version' => 0,
        ]);

        $response->assertRedirect(route('pipelines.index'));
        $this->assertDatabaseHas('pipelines', [
            'id' => $pipeline->id,
            'client_comment' => '新しいコメント',
            'version' => 1,
        ]);
    }

    public function test_update_rejects_stale_version_and_keeps_the_winning_update(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed', 'client_comment' => '元のコメント']);

        $winnerResponse = $this->actingAs($me)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'client_comment' => '先勝ちのコメント',
            'version' => 0,
        ]);
        $winnerResponse->assertRedirect(route('pipelines.index'));

        $loserResponse = $this->actingAs($me)
            ->from(route('pipelines.show', $pipeline->id))
            ->patch('/pipelines/'.$pipeline->id, [
                'client_comment' => '後追いのコメント',
                'version' => 0,
            ]);

        $loserResponse->assertRedirect(route('pipelines.show', $pipeline->id));
        $loserResponse->assertSessionHas('error', '他のユーザーがこのパイプラインを更新しました。最新のデータを表示しました。');

        $this->assertDatabaseHas('pipelines', [
            'id' => $pipeline->id,
            'client_comment' => '先勝ちのコメント',
            'version' => 1,
        ]);
        $this->assertDatabaseMissing('pipelines', [
            'id' => $pipeline->id,
            'client_comment' => '後追いのコメント',
        ]);
    }

    public function test_update_requires_version_field(): void
    {
        $me = User::factory()->create();
        $pipeline = $this->makePipeline($me, ['status' => 'proposed']);

        $this->actingAs($me)->patch('/pipelines/'.$pipeline->id, [
            'client_comment' => 'バージョン未送信',
        ])->assertSessionHasErrors('version');
    }

    // -------------------------------------------------------
    // destroy: DELETE /pipelines/{id} — 認可
    // -------------------------------------------------------

    public function test_guest_cannot_delete_pipeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'proposed']);

        $this->delete('/pipelines/'.$pipeline->id)->assertRedirect('/login');
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
    }

    public function test_general_user_cannot_delete_pipeline(): void
    {
        $user = User::factory()->create(['role' => 'general']);
        $pipeline = $this->makePipeline($user, ['status' => 'proposed']);

        // 削除はドロワー表示中（referer が show URL /pipelines/{id}）から実行される。
        $response = $this->actingAs($user)->delete(
            '/pipelines/'.$pipeline->id,
            [],
            ['X-Inertia' => 'true', 'referer' => '/pipelines/'.$pipeline->id]
        );

        // 設計書 06_進捗管理 DELETE #5：権限不足は 403 を素で投げず、前画面（＝同じドロワー表示）
        // へ戻し flash.error を返す。redirect先を固定しないと一覧へ飛ばす誤実装でも通ってしまうため、
        // referer を明示してリダイレクト先まで検証する（StaleResourceHandlingTestと同粒度）。
        $response->assertStatus(303);
        $response->assertRedirect('/pipelines/'.$pipeline->id);
        $response->assertSessionHas('error', '削除権限がありません。');
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
    }

    public function test_general_user_delete_pipeline_without_referer_falls_back_to_dashboard(): void
    {
        // referer が無い場合（直接リクエスト等）でも flash.error を失わずダッシュボードへ戻る。
        $user = User::factory()->create(['role' => 'general']);
        $pipeline = $this->makePipeline($user, ['status' => 'proposed']);

        $response = $this->actingAs($user)->delete('/pipelines/'.$pipeline->id);

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error', '削除権限がありません。');
        $this->assertDatabaseHas('pipelines', ['id' => $pipeline->id]);
    }

    public function test_admin_can_delete_pipeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'proposed']);

        $response = $this->actingAs($admin)->from(route('pipelines.index'))->delete('/pipelines/'.$pipeline->id);

        $response->assertRedirect(route('pipelines.index'));
        $response->assertSessionHas('success', 'パイプラインを削除しました。');
        $this->assertDatabaseMissing('pipelines', ['id' => $pipeline->id]);
    }

    public function test_destroy_returns_404_for_non_existent_pipeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->delete('/pipelines/99999')->assertNotFound();
    }

    /**
     * ドロワー表示中（referer が show URL /pipelines/{id}）に削除しても、
     * 削除済み ID の show へ戻らず（404 になるため）、index へ戻る。
     * 絞り込み条件（referer のクエリ）は index に引き継ぐ。update の 2026-07-11 修正と同方針。
     */
    public function test_destroy_from_drawer_redirects_to_index_with_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'proposed']);

        $response = $this->actingAs($admin)
            ->from('/pipelines/'.$pipeline->id.'?keyword=foo')
            ->delete('/pipelines/'.$pipeline->id);

        $response->assertRedirect(route('pipelines.index', ['keyword' => 'foo']));
        $response->assertSessionHas('success', 'パイプラインを削除しました。');
        $this->assertDatabaseMissing('pipelines', ['id' => $pipeline->id]);
    }

    public function test_destroy_from_completed_redirects_to_completed_with_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'rejected', 'ended_at' => now()]);

        // 完了済みタブからの削除は completed へ戻り、絞り込み条件を保持する
        $response = $this->actingAs($admin)
            ->from('/pipelines/completed?keyword=bar')
            ->delete('/pipelines/'.$pipeline->id);

        $response->assertRedirect(route('pipelines.completed', ['keyword' => 'bar']));
        $this->assertDatabaseMissing('pipelines', ['id' => $pipeline->id]);
    }

    // -------------------------------------------------------
    // 追加補完テスト（Evaluator 指摘 #5〜#8）
    // -------------------------------------------------------

    public function test_index_sort_by_match_score_desc(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, ['status' => 'proposed', 'match_score' => 60, 'match_rank' => 'C']);
        $this->makePipeline($me, ['status' => 'proposed', 'match_score' => 90, 'match_rank' => 'A']);
        $this->makePipeline($me, ['status' => 'proposed', 'match_score' => 75, 'match_rank' => 'B']);

        $response = $this->actingAs($me)->get('/pipelines?sort=match_score&order=desc');

        // スコア高い順：90 → 75 → 60
        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.cards.0.match_score', 90)
            ->where('columns.0.cards.1.match_score', 75)
            ->where('columns.0.cards.2.match_score', 60)
        );
    }

    public function test_index_sort_by_updated_at_desc(): void
    {
        $me = User::factory()->create();
        $old = $this->makePipeline($me, ['status' => 'proposed', 'match_rank' => 'A']);
        $new = $this->makePipeline($me, ['status' => 'proposed', 'match_rank' => 'B']);

        // updated_at を明示制御（Eloquent のタイムスタンプ自動更新を避けるため直接 UPDATE）
        Pipeline::where('id', $old->id)->update(['updated_at' => '2026-06-01 10:00:00']);
        Pipeline::where('id', $new->id)->update(['updated_at' => '2026-06-20 10:00:00']);

        $response = $this->actingAs($me)->get('/pipelines?sort=updated_at&order=desc');

        // 新しい順：$new（2026-06-20）が先頭
        $response->assertInertia(fn ($page) => $page
            ->where('columns.0.cards.0.id', $new->id)
            ->where('columns.0.cards.1.id', $old->id)
        );
    }

    // 注：keyword の LIKE ワイルドカード（%・_）エスケープは MySQL の
    // バックスラッシュエスケープ前提で実装している。テスト DB は SQLite（:memory:）で
    // LIKE の \ エスケープ既定挙動が MySQL と異なり、本番挙動を忠実に再現できないため、
    // エスケープ専用の Feature テストは設けない（reason.md §8 に記録）。

    public function test_completed_paginates_at_fifty_per_page(): void
    {
        $me = User::factory()->create();
        // 同一人材×案件の UNIQUE 制約を避けるため案件を51件用意して紐付ける
        $engineer = Engineer::factory()->create(['main_user_id' => $me->id]);
        for ($i = 0; $i < 51; $i++) {
            $project = Project::factory()->create(['main_user_id' => $me->id]);
            Pipeline::factory()->create([
                'engineer_id' => $engineer->id,
                'project_id' => $project->id,
                'status' => 'rejected',
                'ended_at' => now()->subDays($i),
            ]);
        }

        $page1 = $this->actingAs($me)->get('/pipelines/completed');
        $page1->assertInertia(fn ($page) => $page
            ->has('pipelines.data', 50)
            ->where('pipelines.meta.per_page', 50)
            ->where('pipelines.meta.total', 51)
            ->where('pipelines.meta.last_page', 2)
            ->where('pipelines.meta.current_page', 1)
        );

        $page2 = $this->actingAs($me)->get('/pipelines/completed?page=2');
        $page2->assertInertia(fn ($page) => $page
            ->has('pipelines.data', 1)
            ->where('pipelines.meta.current_page', 2)
        );
    }

    public function test_admin_can_update_pipeline(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pipeline = $this->makePipeline($admin, ['status' => 'proposed']);

        $response = $this->actingAs($admin)->from(route('pipelines.index'))->patch('/pipelines/'.$pipeline->id, [
            'status' => 'first_waiting',
            'client_comment' => '管理者による更新',
            'version' => 0,
        ]);

        $response->assertRedirect(route('pipelines.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pipelines', [
            'id' => $pipeline->id,
            'status' => 'first_waiting',
            'client_comment' => '管理者による更新',
        ]);
    }
}
