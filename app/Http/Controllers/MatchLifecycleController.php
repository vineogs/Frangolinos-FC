<?php

namespace App\Http\Controllers;

use App\Models\RachaDay;
use App\Services\RachaGameService;
use App\Services\RachaStateStore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MatchLifecycleController extends Controller
{
    public function __construct(private RachaGameService $games, private RachaStateStore $states) {}

    private function change(Request $request, Closure $action): JsonResponse
    {
        $request->validate(['revision' => ['required', 'integer', 'min:0']]);

        $this->states->ensure();

        return DB::transaction(function () use ($request, $action): JsonResponse {
            $row = DB::table('racha_states')->where('id', 1)->lockForUpdate()->first();
            if ($row->revision !== $request->integer('revision')) {
                return response()->json(['message' => 'O racha mudou em outro dispositivo. Recarregue a página.'], 409);
            }
            $data = $action(json_decode($row->data, true));
            DB::table('racha_states')->where('id', 1)->update(['data' => json_encode($data, JSON_THROW_ON_ERROR), 'revision' => $row->revision + 1]);

            return response()->json(['data' => $data, 'revision' => $row->revision + 1]);
        }, 3);
    }

    public function finish(Request $request): JsonResponse
    {
        return $this->change($request, fn (array $data): array => $this->games->finish($data));
    }

    public function penalties(Request $request): JsonResponse
    {
        $winner = $request->validate(['winner' => ['required', 'in:a,b']])['winner'];

        return $this->change($request, fn (array $data): array => $this->games->finish($data, $winner));
    }

    public function next(Request $request): JsonResponse
    {
        $input = $request->validate(['dayId' => ['required', 'uuid', 'exists:racha_days,id'], 'duration' => ['sometimes', 'integer', 'in:10,15']]);

        return $this->change($request, function (array $data) use ($input): array {
            if ($data['current'] !== null) {
                throw ValidationException::withMessages(['match' => 'Finalize a partida atual, incluindo o desempate, antes de preparar outra.']);
            }
            $data['current'] = $this->games->nextLineup($data, RachaDay::findOrFail($input['dayId']), true);
            $data['current']['duration'] = $input['duration'] ?? 10;
            $data['next'] = null;
            $data['nextMessage'] = null;

            return $data;
        });
    }

    public function substitute(Request $request): JsonResponse
    {
        $input = $request->validate(['out' => ['required', 'uuid'], 'in' => ['required', 'uuid']]);

        return $this->change($request, function (array $data) use ($input): array {
            $data = $this->games->normalize($data);
            if ($data['current'] === null || ! isset($data['current']['dayId'])) {
                throw ValidationException::withMessages(['match' => 'Não há partida deste dia em andamento.']);
            }

            return $this->games->substitute($data, $input['out'], $input['in'], false, RachaDay::findOrFail($data['current']['dayId']));
        });
    }

    public function availability(Request $request, RachaDay $day): JsonResponse
    {
        $input = $request->validate(['player' => ['required', 'uuid'], 'available' => ['required', 'boolean'], 'replacement' => ['nullable', 'uuid']]);

        return $this->change($request, function (array $data) use ($input, $day): array {
            $data = $this->games->normalize($data);
            $locked = RachaDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            if ($locked->finished_at !== null || ! in_array($input['player'], $locked->attendees, true)) {
                throw ValidationException::withMessages(['player' => 'O jogador precisa ter presença confirmada em um dia ainda aberto.']);
            }
            $departed = array_values(array_filter($locked->departed ?? [], fn (string $id): bool => $id !== $input['player']));
            if (! $input['available']) {
                $departed[] = $input['player'];
            }
            $locked->update(['departed' => $departed]);
            if ($input['available']) {
                return $this->games->refreshNext($data, $locked);
            }

            return $this->games->substitute($data, $input['player'], $input['replacement'] ?? null, true, $locked);
        });
    }

    public function arrival(Request $request, RachaDay $day): JsonResponse
    {
        $input = $request->validate(['player' => ['required', 'uuid'], 'arrived' => ['required', 'boolean']]);

        return $this->change($request, function (array $data) use ($day, $input): array {
            $locked = RachaDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            if ($locked->finished_at !== null || ! in_array($input['player'], $locked->attendees, true)) {
                throw ValidationException::withMessages(['player' => 'Selecione um participante inscrito em um racha aberto.']);
            }
            $playing = ($data['current']['dayId'] ?? null) === $day->id ? array_merge($data['current']['teams']['a'], $data['current']['teams']['b']) : [];
            if (! $input['arrived'] && in_array($input['player'], $playing, true)) {
                throw ValidationException::withMessages(['player' => 'O jogador está em campo. Registre sua saída com uma substituição.']);
            }
            $arrived = array_values(array_diff($locked->arrived_players ?? $locked->attendees, [$input['player']]));
            if ($input['arrived']) {
                $arrived[] = $input['player'];
            }
            $locked->update(['arrived_players' => $arrived]);
            if (($data['current']['dayId'] ?? null) === $day->id) {
                $data['current']['bench'] = array_values(array_diff(array_column($this->games->available($data, $locked), 'id'), $playing));
            }

            return $this->games->refreshNext($data, $locked);
        });
    }

    public function closeDay(Request $request, RachaDay $day): JsonResponse
    {
        return $this->change($request, function (array $data) use ($day): array {
            $locked = RachaDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            if (($data['current']['dayId'] ?? null) === $day->id) {
                $match = $data['current'];
                if ($match['startedAt'] !== null || $match['elapsed'] > 0 || $match['goals'] !== [] || ($match['status'] ?? 'regular') === 'penalties') {
                    throw ValidationException::withMessages(['match' => 'Finalize a partida e resolva os pênaltis antes de encerrar o dia.']);
                }
                $data['current'] = null;
            }
            if ($locked->finished_at === null) {
                $locked->update(['finished_at' => now(), 'statistics' => $this->games->statistics($data, $locked)]);
            }
            if (($data['next']['dayId'] ?? null) === $day->id) {
                $data['next'] = null;
                $data['nextMessage'] = 'Dia de racha encerrado.';
            }

            return $data;
        });
    }

    public function statistics(RachaDay $day): JsonResponse
    {
        $data = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);

        return response()->json($day->statistics ?? $this->games->statistics($data, $day));
    }
}
