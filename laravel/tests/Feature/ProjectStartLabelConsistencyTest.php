<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 参画開始時期ラベル（start_label）が、案件一覧・案件詳細・マッチング結果の3画面で
 * 同一の文字列になることを検証する（#57 の受け入れ条件）。
 *
 * 以前は3つの Resource に同じ生成式がコピペされており、片方だけ書式を変えても気づけなかった。
 * ここでは「同じ案件なら3経路の値が一致する」ことを直接アサートし、集約先（Project::startLabel）が
 * 壊れた場合に必ず落ちるようにしている。
 */
class ProjectStartLabelConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** マッチングエンジン（Python）の応答を差し込む。実 HTTP には出ない。 */
    private function fakeEngine(int $projectId): void
    {
        Http::fake([
            '*/api/v1/matching/calculate' => Http::response([
                'engineer_id' => 1,
                'generated_at' => now()->toIso8601String(),
                'matches' => [[
                    'project_id' => $projectId,
                    'match_score' => 90,
                    'match_rank' => 'A',
                    'ai_score_reason' => '理由',
                    'ai_comment' => '推薦',
                    'ai_missing' => null,
                ]],
            ], 200),
        ]);
    }

    /**
     * 案件一覧・案件詳細・マッチング結果から、同一案件の start_label を取り出す。
     *
     * @return array{list: mixed, detail: mixed, matching: mixed}
     */
    private function fetchLabels(User $user, Project $project, Engineer $engineer): array
    {
        $list = $this->actingAs($user)->get('/projects');
        $list->assertOk();
        $listLabel = collect($list->viewData('page')['props']['projects']['data'])
            ->firstWhere('id', $project->id)['start_label'];

        $detail = $this->actingAs($user)->get("/projects/{$project->id}");
        $detail->assertOk();
        $detailLabel = $detail->viewData('page')['props']['project']['start_label'];

        $this->fakeEngine($project->id);
        $matching = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");
        $matching->assertOk();
        $matchingLabel = $matching->viewData('page')['props']['results'][0]['project']['start_label'];

        return ['list' => $listLabel, 'detail' => $detailLabel, 'matching' => $matchingLabel];
    }

    public function test_start_label_is_identical_across_list_detail_and_matching(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create([
            'main_user_id' => $user->id,
            'start_date' => '2026-01-05',
        ]);

        $labels = $this->fetchLabels($user, $project, $engineer);

        // 期待値そのものも固定する（3つが揃って同じ間違いをしても落ちるようにするため）。
        $this->assertSame('2026/01/05〜', $labels['list']);
        $this->assertSame($labels['list'], $labels['detail']);
        $this->assertSame($labels['list'], $labels['matching']);
    }

    public function test_start_label_is_undecided_in_all_three_when_start_date_is_null(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create([
            'main_user_id' => $user->id,
            'start_date' => null,
        ]);

        $labels = $this->fetchLabels($user, $project, $engineer);

        $this->assertSame('未定', $labels['list']);
        $this->assertSame('未定', $labels['detail']);
        $this->assertSame('未定', $labels['matching']);
    }

    public function test_start_date_itself_is_still_returned_separately_for_matching(): void
    {
        // マッチングカードは「値の有無」で欠損トークン（参画開始時期未定）に切り替えるため、
        // ラベルとは別に start_date（null 可）が返り続ける必要がある。
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create([
            'main_user_id' => $user->id,
            'start_date' => null,
        ]);

        $this->fakeEngine($project->id);
        $response = $this->actingAs($user)->get("/engineers/{$engineer->id}/matching");

        $response->assertInertia(fn ($page) => $page
            ->where('results.0.project.start_date', null)
            ->where('results.0.project.start_label', '未定')
        );
    }
}
