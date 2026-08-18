<?php

namespace Database\Seeders;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 進捗管理画面の目視確認用データを投入する。
 * マッチング画面（生成経路）が未実装のため、進行中12種・終了4種にまたがる
 * 多様なパイプラインをここで作成する。
 */
class PipelineSeeder extends Seeder
{
    public function run(): void
    {
        // 担当営業（既存の Test User を含めつつ複数用意）
        $admin = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'role' => 'admin']
        );
        $general1 = User::factory()->create(['name' => '営業 一郎', 'role' => 'general']);
        $general2 = User::factory()->create(['name' => '営業 二郎', 'role' => 'general']);

        $salesUsers = [$admin, $general1, $general2];

        // 案件を複数用意（担当はランダムな営業）
        $projects = collect(range(1, 6))->map(fn ($i) => Project::factory()->create([
            'name' => "案件{$i}",
            'client_name' => "顧客{$i}",
            'main_user_id' => $salesUsers[array_rand($salesUsers)]->id,
        ]));

        // 進行中12種：各ステータスに 2 件ずつ
        foreach (Pipeline::inProgressValues() as $status) {
            for ($n = 0; $n < 2; $n++) {
                $main = $salesUsers[array_rand($salesUsers)];
                $engineer = Engineer::factory()->create([
                    'main_user_id' => $main->id,
                    'sub_user_id' => null,
                ]);
                Pipeline::factory()->inProgress($status)->create([
                    'engineer_id' => $engineer->id,
                    'project_id' => $projects->random()->id,
                ]);
            }
        }

        // 終了4種：各ステータスに 2 件ずつ
        foreach (Pipeline::terminalValues() as $status) {
            for ($n = 0; $n < 2; $n++) {
                $main = $salesUsers[array_rand($salesUsers)];
                $engineer = Engineer::factory()->create([
                    'main_user_id' => $main->id,
                    'sub_user_id' => null,
                ]);
                Pipeline::factory()->terminal($status)->create([
                    'engineer_id' => $engineer->id,
                    'project_id' => $projects->random()->id,
                ]);
            }
        }
    }
}
