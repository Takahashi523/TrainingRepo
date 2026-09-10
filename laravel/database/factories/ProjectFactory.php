<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' 案件',
            'client_name' => $this->faker->company(),
            'status' => 'open',
            'main_user_id' => User::factory(),
            'sub_user_id' => null,
        ];
    }
}
