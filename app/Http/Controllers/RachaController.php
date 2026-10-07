<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRachaRequest;
use App\Services\RachaGameService;
use App\Services\RachaStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class RachaController extends Controller
{
    public function __construct(private RachaGameService $games, private RachaStateStore $states) {}

    public function show(): JsonResponse
    {
        $state = $this->states->ensure();

        $data = json_decode($state->data, true);
        $completed = $this->games->normalize($data);
        if ($completed !== $data) {
            DB::table('racha_states')->where('id', 1)->where('revision', $state->revision)->update([
                'data' => json_encode($completed, JSON_THROW_ON_ERROR),
                'revision' => $state->revision + 1,
            ]);
            $state = DB::table('racha_states')->find(1);
        }

        return response()->json(['revision' => $state->revision, 'data' => json_decode($state->data, true)]);
    }

    public function update(SaveRachaRequest $request): JsonResponse
    {
        $this->states->ensure();

        return DB::transaction(function () use ($request): JsonResponse {
            $input = $request->validated();
            DB::table('racha_states')->where('id', 1)->lockForUpdate()->first();
            $stored = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);
            unset($input['data']['next'], $input['data']['nextMessage']);
            foreach (['next', 'nextMessage'] as $key) {
                if (array_key_exists($key, $stored)) {
                    $input['data'][$key] = $stored[$key];
                }
            }
            $input['data'] = $this->games->normalize($input['data']);
            $updated = DB::table('racha_states')->where('id', 1)->where('revision', $input['revision'])->update([
                'data' => json_encode($input['data'], JSON_THROW_ON_ERROR),
                'revision' => $input['revision'] + 1,
            ]);

            if (! $updated) {
                return response()->json(['message' => 'O racha foi atualizado em outra aba. Recarregue a página.'], 409);
            }

            $this->states->syncPlayers($input['data']['players']);

            return response()->json(['revision' => $input['revision'] + 1, 'data' => $input['data']]);
        }, 3);
    }
}
