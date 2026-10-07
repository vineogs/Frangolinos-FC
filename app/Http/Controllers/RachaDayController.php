<?php

namespace App\Http\Controllers;

use App\Models\RachaDay;
use App\Services\RachaStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RachaDayController extends Controller
{
    public function __construct(private RachaStateStore $states) {}

    public function index(): JsonResponse
    {
        return response()->json(RachaDay::orderBy('date')->orderBy('time')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'location' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(RachaDay::create([...$data, 'attendees' => []]), 201);
    }

    public function update(Request $request, RachaDay $day): JsonResponse
    {
        $data = $request->validate(['player' => ['required', 'uuid'], 'present' => ['required', 'boolean']]);
        $this->states->ensure();
        $updated = DB::transaction(function () use ($day, $data): RachaDay {
            $locked = RachaDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            if ($locked->finished_at !== null) {
                throw ValidationException::withMessages(['day' => 'Este dia de racha já foi encerrado.']);
            }
            $state = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);
            $player = collect($state['players'])->firstWhere('id', $data['player']);
            if (! $player || ($data['present'] && ($player['active'] ?? true) === false)) {
                throw ValidationException::withMessages(['player' => 'Selecione um jogador ativo cadastrado no racha.']);
            }
            if (! $data['present'] && ($state['current']['dayId'] ?? null) === $day->id && in_array($data['player'], array_merge($state['current']['teams']['a'], $state['current']['teams']['b']), true)) {
                throw ValidationException::withMessages(['player' => 'Este jogador está escalado. Finalize a partida antes de retirar a presença.']);
            }
            $attendees = array_values(array_filter($locked->attendees, fn (string $id): bool => $id !== $data['player']));
            if ($data['present']) {
                $attendees[] = $data['player'];
            }
            $locked->update(['attendees' => $attendees]);

            return $locked;
        }, 3);

        return response()->json($updated);
    }
}
