<?php

namespace App\Models;

use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

#[Fillable(['id', 'name', 'position', 'active'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
