<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Dashboard ページの Vite マニフェスト解決を無効化（Props 検証には不要）。
        $this->withoutVite();
    }

    /**
     * 指定担当・ステータスのパイプラインを作る（人材経由で担当条件を満たす）。
     */
    private function makePipeline(
        User $mainUser,
        string $status = 'proposed',
        ?string $nextActionDate = null,
        ?User $engineerSub = null,
    ): Pipeline {
        $engineer = Engineer::factory()->create([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $engineerSub?->id,
        ]);
        $project = Project::factory()->create(['main_user_id' => $mainUser->id]);

        return Pipeline::factory()->create([
            'engineer_id' => $engineer->id,
            'project_id' => $project->id,
            'status' => $status,
            'next_action_date' => $nextActionDate,
        ]);
    }

    // -------------------------------------------------------
    // 認可
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_general_user_can_view_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'general']);

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard', false));
    }

    public function test_admin_user_can_view_dashboard(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    // -------------------------------------------------------
    // KPI
    // -------------------------------------------------------

    public function test_kpi_counts_only_own_engineers_and_projects(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        // 提案可能人材：自分メイン1 + 自分サブ1 + 他人1（全体3・自分2）
        Engineer::factory()->create(['status' => 'proposable', 'main_user_id' => $me->id]);
        Engineer::factory()->create(['status' => 'proposable', 'main_user_id' => $other->id, 'sub_user_id' => $me->id]);
        Engineer::factory()->create(['status' => 'proposable', 'main_user_id' => $other->id]);
        // 別ステータスはカウントしない（境界）
        Engineer::factory()->create(['status' => 'not_proposable', 'main_user_id' => $me->id]);

        // 稼働中案件：自分メイン1 + 他人1（全体2・自分1）
        Project::factory()->create(['status' => 'open', 'main_user_id' => $me->id]);
        Project::factory()->create(['status' => 'open', 'main_user_id' => $other->id]);
        Project::factory()->create(['status' => 'closed', 'main_user_id' => $me->id]);

        $this->actingAs($me)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('kpi.proposable_engineer_count', 2)
            ->where('kpi.proposable_engineer_count_total', 3)
            ->where('kpi.open_project_count', 1)
            ->where('kpi.open_project_count_total', 2)
        );
    }

    public function test_active_pipeline_count_excludes_terminal_statuses(): void
    {
        $me = User::factory()->create();

        // 進行中2種 + 終了1種（active は進行中2のみ）
        $this->makePipeline($me, 'proposed');
        $this->makePipeline($me, 'offered');
        $this->makePipeline($me, 'rejected'); // 終了＝除外
        // 他人の進行中は除外
        $this->makePipeline(User::factory()->create(), 'proposed');

        $this->actingAs($me)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('kpi.active_pipeline_count', 2)
        );
    }

    // -------------------------------------------------------
    // pipeline_summary
    // -------------------------------------------------------

    public function test_pipeline_summary_returns_all_twelve_in_progress_statuses(): void
    {
        $me = User::factory()->create();
        $this->makePipeline($me, 'proposed');

        $this->actingAs($me)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('pipeline_summary', 12)
            ->where('pipeline_summary.0.status', 'proposed')
            ->where('pipeline_summary.0.count', 1)
            ->where('pipeline_summary.0.percentage', 100)
            ->where('pipeline_summary.0.group', 'entry')
        );
    }

    public function test_pipeline_summary_percentage_uses_floor(): void
    {
        $me = User::factory()->create();
        // proposed 1 / applying 2 → total 3。proposed=33%（floor(33.3)）、applying=66%（floor(66.6)）
        $this->makePipeline($me, 'proposed');
        $this->makePipeline($me, 'applying');
        $this->makePipeline($me, 'applying');

        $this->actingAs($me)->get('/dashboard')->assertInertia(function ($page) {
            $summary = collect($page->toArray()['props']['pipeline_summary']);
            $proposed = $summary->firstWhere('status', 'proposed');
            $applying = $summary->firstWhere('status', 'applying');
            $this->assertSame(33, $proposed['percentage']);
            $this->assertSame(66, $applying['percentage']);
        });
    }

    public function test_pipeline_summary_returns_zero_percent_when_no_active(): void
    {
        $me = User::factory()->create();
        // 進行中カードなし（終了のみ）→ active=0 でゼロ除算せず全行0%
        $this->makePipeline($me, 'rejected');

        $this->actingAs($me)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('kpi.active_pipeline_count', 0)
            ->has('pipeline_summary', 12)
            ->where('pipeline_summary.0.percentage', 0)
            ->where('pipeline_summary.11.percentage', 0)
        );
    }

    // -------------------------------------------------------
    // upcoming_actions
    // -------------------------------------------------------

    public function test_upcoming_actions_returns_within_seven_days_and_overdue_sorted_asc(): void
    {
        $me = User::factory()->create();
        $today = Carbon::today();

        $this->makePipeline($me, 'proposed', $today->copy()->addDays(8)->toDateString()); // 範囲外・除外
        $this->makePipeline($me, 'proposed', $today->copy()->addDays(7)->toDateString()); // 境界内
        $this->makePipeline($me, 'proposed', $today->toDateString());                       // 今日
        $this->makePipeline($me, 'proposed', $today->copy()->subDay()->toDateString());     // 昨日＝overdue
        $this->makePipeline($me, 'proposed', null);                                          // null・除外

        $this->actingAs($me)->get('/dashboard')->assertInertia(function ($page) use ($today) {
            $actions = $page->toArray()['props']['upcoming_actions'];
            // 8日後・null を除いた3件が昇順で返る
            $this->assertCount(3, $actions);
            $this->assertSame($today->copy()->subDay()->toDateString(), $actions[0]['next_action_date']);
            $this->assertTrue($actions[0]['is_overdue']);
            $this->assertSame($today->toDateString(), $actions[1]['next_action_date']);
            $this->assertFalse($actions[1]['is_overdue']);
            $this->assertSame($today->copy()->addDays(7)->toDateString(), $actions[2]['next_action_date']);
            $this->assertFalse($actions[2]['is_overdue']);
        });
    }

    public function test_upcoming_actions_empty_when_none(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('upcoming_actions', 0)
        );
    }

    public function test_upcoming_actions_are_capped_at_five_most_urgent(): void
    {
        $me = User::factory()->create();
        $today = Carbon::today();

        // 7件（すべて対象）を作成。昇順で最も緊急な上位5件だけ返ること。
        for ($i = 0; $i < 7; $i++) {
            $this->makePipeline($me, 'proposed', $today->copy()->addDays($i)->toDateString());
        }

        $this->actingAs($me)->get('/dashboard')->assertInertia(function ($page) use ($today) {
            $actions = $page->toArray()['props']['upcoming_actions'];
            $this->assertCount(5, $actions);
            // 先頭＝今日（最も早い日付）、末尾＝+4日（5件目）。+5/+6 は溢れる。
            $this->assertSame($today->toDateString(), $actions[0]['next_action_date']);
            $this->assertSame($today->copy()->addDays(4)->toDateString(), $actions[4]['next_action_date']);
        });
    }

    public function test_upcoming_actions_exclude_terminal_pipelines(): void
    {
        $me = User::factory()->create();
        $today = Carbon::today();

        // 進行中は含む、終了（rejected/closed 等）は next_action_date があっても除外。
        $this->makePipeline($me, 'proposed', $today->toDateString());
        $this->makePipeline($me, 'rejected', $today->toDateString());
        $this->makePipeline($me, 'closed', $today->toDateString());

        $this->actingAs($me)->get('/dashboard')->assertInertia(function ($page) {
            $actions = $page->toArray()['props']['upcoming_actions'];
            $this->assertCount(1, $actions);
            $this->assertSame('proposed', $actions[0]['status']);
        });
    }

    public function test_upcoming_actions_only_include_own_pipelines(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $today = Carbon::today();

        $this->makePipeline($other, 'proposed', $today->toDateString());

        $this->actingAs($me)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->has('upcoming_actions', 0)
        );
    }

    public function test_upcoming_actions_eager_load_relations_without_n_plus_one(): void
    {
        $me = User::factory()->create();
        $today = Carbon::today();
        // 3件作って追加クエリが件数に比例しないことを確認
        $this->makePipeline($me, 'proposed', $today->toDateString());
        $this->makePipeline($me, 'proposed', $today->toDateString());
        $this->makePipeline($me, 'proposed', $today->toDateString());

        DB::enableQueryLog();
        $response = $this->actingAs($me)->get('/dashboard');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertInertia(function ($page) {
            $actions = $page->toArray()['props']['upcoming_actions'];
            $this->assertCount(3, $actions);
            // Eager Load したリレーションが含まれる
            $this->assertArrayHasKey('name', $actions[0]['engineer']);
            $this->assertArrayHasKey('name', $actions[0]['project']);
        });

        // KPI(5) + summary(1) + upcoming(1) + engineer eager(1) + project eager(1) 程度に収まる。
        // 件数に比例して増える（N+1）ことがないよう十分小さい上限で担保する。
        $this->assertLessThan(15, $queryCount, "クエリ数が多すぎます（N+1 の疑い）: {$queryCount}");
    }

    public function test_upcoming_actions_render_when_project_client_name_null(): void
    {
        // client_name は表示不要のため Props に含めない。DB 上 null でも project.name が正しく返ること。
        $me = User::factory()->create();
        $today = Carbon::today();

        $engineer = Engineer::factory()->create(['main_user_id' => $me->id]);
        $project = Project::factory()->create([
            'main_user_id' => $me->id,
            'name' => '○○案件',
            'client_name' => null,
        ]);
        Pipeline::factory()->create([
            'engineer_id' => $engineer->id,
            'project_id' => $project->id,
            'status' => 'proposed',
            'next_action_date' => $today->toDateString(),
        ]);

        $this->actingAs($me)->get('/dashboard')->assertOk()
            ->assertInertia(function ($page) {
                $action = $page->toArray()['props']['upcoming_actions'][0];
                $this->assertSame('○○案件', $action['project']['name']);
                // client_name は公開しない。
                $this->assertArrayNotHasKey('client_name', $action['project']);
            });
    }
}
