<?php

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Facades\DB;
use stdClass;

class RachaStateStore
{
    public function ensure(): stdClass
    {
        if (! DB::table('racha_states')->where('id', 1)->exists()) {
            $players = Player::orderBy('created_at')->get(['id', 'name', 'position', 'active'])->toArray();
            DB::table('racha_states')->insertOrIgnore(['id' => 1, 'revision' => 0, 'data' => json_encode(['players' => $players, 'matches' => [], 'current' => null], JSON_THROW_ON_ERROR)]);
        }

        return DB::table('racha_states')->find(1);
    }

    public function syncPlayers(array $players): void
    {
        foreach ($players as $player) {
            Player::updateOrCreate(['id' => $player['id']], ['name' => $player['name'], 'position' => $player['position'] ?? 'outfield', 'active' => $player['active'] ?? true]);
        }
    }
}
