<?php

namespace App\Models;

use Database\Factories\RachaDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['date', 'time', 'end_time', 'location', 'attendees', 'departed', 'finished_at', 'statistics', 'paid_players', 'declined_players'])]
class RachaDay extends Model
{
    /** @use HasFactory<RachaDayFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['attendees' => 'array', 'departed' => 'array', 'finished_at' => 'datetime', 'statistics' => 'array', 'paid_players' => 'array', 'declined_players' => 'array'];
    }
}
