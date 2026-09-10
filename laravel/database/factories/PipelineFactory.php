<?php

namespace Database\Factories;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'engineer_id' => Engineer::factory(),
            'project_id' => Project::factory(),
            'status' => 'proposed',
            'match_score' => $this->faker->numberBetween(40, 99),
            'match_rank' => $this->faker->randomElement(['A', 'B', 'C', 'D']),
            'ai_score_reason' => 'スキルセットが案件要件と高く一致しています。',
            'ai_comment' => 'コミュニケーション能力も高く、推薦できます。',
            'ai_missing' => '英語での折衝経験がやや不足しています。',
            'client_comment' => null,
            'ng_reason' => null,
            'next_action_date' => $this->faker->optional()->dateTimeBetween('now', '+1 month')?->format('Y-m-d'),
            'ended_at' => null,
        ];
    }

    /**
     * 進行中ステータスを指定する（指定なければ proposed のまま）。
     */
    public function inProgress(?string $status = null): static
    {
        return $this->state(fn () => [
            'status' => $status ?? $this->faker->randomElement(Pipeline::inProgressValues()),
            'ended_at' => null,
        ]);
    }

    /**
     * 終了ステータスにする（ended_at を記録）。
     */
    public function terminal(?string $status = null): static
    {
        return $this->state(fn () => [
            'status' => $status ?? $this->faker->randomElement(Pipeline::terminalValues()),
            'ng_reason' => '選考基準を満たさなかったため。',
            'ended_at' => $this->faker->dateTimeBetween('-2 months', 'now'),
        ]);
    }
}
