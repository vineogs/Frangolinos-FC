<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\RachaDay;
use App\Notifications\RachaScheduled;
use App\Services\RachaStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
        $data = $this->scheduleData($request);

        $day = DB::transaction(function () use ($data): RachaDay {
            $day = RachaDay::create([...$data, 'attendees' => [], 'arrived_players' => []]);
            $this->notifyProfiles($day);

            return $day;
        });

        return response()->json($day, 201);
    }

    public function schedule(Request $request, RachaDay $day): JsonResponse
    {
        $data = $this->scheduleData($request);
        $updated = DB::transaction(function () use ($day, $data): RachaDay {
            $locked = RachaDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            if ($locked->finished_at !== null) {
                throw ValidationException::withMessages(['day' => 'Este dia de racha já foi encerrado.']);
            }
            $locked->fill($data);
            if ($locked->isDirty(['date', 'time', 'end_time', 'location'])) {
                $locked->save();
                $this->notifyProfiles($locked, true);
            }

            return $locked;
        }, 3);

        return response()->json($updated);
    }

    private function scheduleData(Request $request): array
    {
        return $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:time'],
            'location' => ['required', 'string', 'max:120'],
        ]);
    }

    private function notifyProfiles(RachaDay $day, bool $changed = false): void
    {
        $state = json_decode($this->states->ensure()->data, true);
        $this->states->syncPlayers($state['players']);
        Notification::send(Player::whereIn('id', array_column($state['players'], 'id'))->get(), new RachaScheduled($day, $changed));
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
                $goalkeeper = ($player['position'] ?? 'outfield') === 'goalkeeper';
                $count = collect($state['players'])->filter(fn (array $candidate): bool => in_array($candidate['id'], $attendees, true) && (($candidate['position'] ?? 'outfield') === 'goalkeeper') === $goalkeeper)->count();
                if ($count >= ($goalkeeper ? 4 : 15)) {
                    throw ValidationException::withMessages(['player' => $goalkeeper ? 'As 4 vagas de goleiro já estão preenchidas.' : 'As 15 vagas de jogadores de linha já estão preenchidas.']);
                }
                $attendees[] = $data['player'];
            }
            $declined = array_values(array_filter($locked->declined_players ?? [], fn (string $id): bool => $id !== $data['player']));
            if (! $data['present']) {
                $declined[] = $data['player'];
            }
            $locked->update(['attendees' => $attendees, 'declined_players' => $declined]);

            return $locked;
        }, 3);

        return response()->json($updated);
    }
}
