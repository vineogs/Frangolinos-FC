<?php

namespace Database\Factories;

use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Player> */
class PlayerFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->firstName(), 'position' => 'outfield', 'active' => true];
    }
}
