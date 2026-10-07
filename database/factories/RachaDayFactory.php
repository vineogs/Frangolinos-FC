<?php

namespace Database\Factories;

use App\Models\RachaDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RachaDay> */
class RachaDayFactory extends Factory
{
    public function definition(): array
    {
        return ['date' => '2026-10-10', 'time' => '19:00', 'end_time' => '21:00', 'location' => 'Quadra dos amigos', 'attendees' => []];
    }
}
