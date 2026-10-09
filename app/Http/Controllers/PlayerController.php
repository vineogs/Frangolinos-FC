<?php

namespace App\Http\Controllers;

use App\Services\RachaStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlayerController extends Controller
{
    public function store(Request $request, RachaStateStore $states): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:60'], 'position' => ['required', 'in:outfield,goalkeeper,both']]);
        $states->ensure();

        return DB::transaction(function () use ($input, $states): JsonResponse {
            $row = DB::table('racha_states')->where('id', 1)->lockForUpdate()->first();
            $data = json_decode($row->data, true);
            $name = trim($input['name']);
            if ($name === '' || count($data['players']) >= 200) {
                throw ValidationException::withMessages(['name' => 'Informe um nome válido. O limite é de 200 jogadores.']);
            }
            foreach ($data['players'] as $player) {
                if (mb_strtolower(trim($player['name'])) === mb_strtolower($name)) {
                    throw ValidationException::withMessages(['name' => 'Este nome já está cadastrado. Escolha o perfil existente ou use um apelido diferente.']);
                }
            }
            $player = ['id' => (string) Str::uuid(), 'name' => $name, 'position' => $input['position'], 'active' => true];
            $data['players'][] = $player;
            $states->syncPlayers($data['players']);
            DB::table('racha_states')->where('id', 1)->update(['data' => json_encode($data, JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1]);

            return response()->json(['player' => $player, 'data' => $data, 'revision' => $row->revision + 1], 201);
        }, 3);
    }
}
