<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * マッチング結果画面からのパイプライン生成（POST /pipelines）。
 * 重複・上限（1案件5件）チェックは PipelineService@create のトランザクションで行う。
 */
class PipelineStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Engineer $engineer, Project $project, array $overrides = []): array
    {
        return array_merge([
            'engineer_id' => $engineer->id,
            'project_id' => $project->id,
            'match_score' => 88,
            'match_rank' => 'A',
            'ai_score_reason' => 'スキル一致',
            'ai_comment' => '推薦できます',
            'ai_missing' => null,
        ], $overrides);
    }

    public function test_guest_cannot_store(): void
    {
        $engineer = Engineer::factory()->create();
        $project = Project::factory()->create();

        $this->post('/pipelines', $this->payload($engineer, $project))->assertRedirect('/login');
    }

    public function test_pipeline_is_created_with_proposed_status_and_snapshot(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/pipelines', $this->payload($engineer, $project));

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('pipelines', [
            'engineer_id' => $engineer->id,
            'project_id' => $project->id,
            'status' => 'proposed',
            'match_score' => 88,
            'match_rank' => 'A',
            'ai_score_reason' => 'スキル一致',
        ]);
    }

    public function test_duplicate_addition_is_rejected_with_422(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);
        Pipeline::factory()->create(['engineer_id' => $engineer->id, 'project_id' => $project->id]);

        $response = $this->actingAs($user)
            ->from("/engineers/{$engineer->id}/matching")
            ->post('/pipelines', $this->payload($engineer, $project));

        $response->assertSessionHasErrors('project_id');
        $this->assertSame(1, Pipeline::where('project_id', $project->id)->count());
    }

    public function test_fifth_addition_succeeds_but_sixth_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['main_user_id' => $user->id]);

        // 4件の別人材で埋める（境界の手前）
        Pipeline::factory()->count(4)->create(['project_id' => $project->id]);

        $engineer5 = Engineer::factory()->create(['main_user_id' => $user->id]);
        // 5件目は成功
        $this->actingAs($user)->post('/pipelines', $this->payload($engineer5, $project))
            ->assertSessionHasNoErrors();
        $this->assertSame(5, Pipeline::where('project_id', $project->id)->count());

        // 6件目は上限で拒否
        $engineer6 = Engineer::factory()->create(['main_user_id' => $user->id]);
        $response = $this->actingAs($user)
            ->from("/engineers/{$engineer6->id}/matching")
            ->post('/pipelines', $this->payload($engineer6, $project));

        $response->assertSessionHasErrors('project_id');
        $this->assertSame(5, Pipeline::where('project_id', $project->id)->count());
    }

    public function test_validation_rejects_invalid_score_and_rank(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);

        $this->actingAs($user)
            ->from("/engineers/{$engineer->id}/matching")
            ->post('/pipelines', $this->payload($engineer, $project, ['match_score' => 150]))
            ->assertSessionHasErrors('match_score');

        $this->actingAs($user)
            ->from("/engineers/{$engineer->id}/matching")
            ->post('/pipelines', $this->payload($engineer, $project, ['match_rank' => 'Z']))
            ->assertSessionHasErrors('match_rank');
    }

    public function test_validation_requires_engineer_and_project(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/pipelines')
            ->post('/pipelines', ['match_score' => 80, 'match_rank' => 'A'])
            ->assertSessionHasErrors(['engineer_id', 'project_id']);
    }

    /**
     * 計算中〜結果表示中に対象人材が削除された状態で追加すると、既定の back リダイレクトでは
     * 削除済み人材のマッチング画面に戻り 404 になる。人材一覧へ誘導し、エラーを通知することを検証する。
     */
    public function test_missing_engineer_redirects_to_engineer_index_instead_of_404(): void
    {
        $user = User::factory()->create();
        $engineer = Engineer::factory()->create(['main_user_id' => $user->id]);
        $project = Project::factory()->create(['main_user_id' => $user->id]);

        // マッチング表示中に取得したスナップショット。この後、対象人材が削除される状況を模す。
        $payload = $this->payload($engineer, $project);
        $engineer->delete();

        $response = $this->actingAs($user)
            ->from("/engineers/{$engineer->id}/matching")
            ->post('/pipelines', $payload);

        // back（削除済み人材のマッチング画面＝404）ではなく、人材一覧へ誘導しエラーを通知する。
        $response->assertRedirect(route('engineers.index'));
        $response->assertSessionHas('error');

        // パイプラインは作られない（存在検証・FK で防がれる）。
        $this->assertDatabaseCount('pipelines', 0);
    }
}
