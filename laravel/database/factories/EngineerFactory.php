<?php

namespace Database\Factories;

use App\Models\Engineer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Engineer>
 */
class EngineerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'         => $this->faker->name(),
            'name_kana'    => 'ヤマダタロウ',
            'status'       => 'proposable',
            'main_user_id' => User::factory(),
            'sub_user_id'  => null,
        ];
    }
}
