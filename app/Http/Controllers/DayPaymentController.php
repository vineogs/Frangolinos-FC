<?php

namespace App\Http\Controllers;

use App\Models\RachaDay;
use App\Services\RachaStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DayPaymentController extends Controller
{
    public function __construct(private RachaStateStore $states) {}

    public function update(Request $request, RachaDay $day): JsonResponse
    {
        $input = $request->validate(['player' => ['required', 'uuid'], 'paid' => ['required', 'boolean']]);
        $this->states->ensure();
        $updated = DB::transaction(function () use ($input, $day): RachaDay {
            $locked = RachaDay::whereKey($day->id)->lockForUpdate()->firstOrFail();
            $state = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);
            if (! in_array($input['player'], array_column($state['players'], 'id'), true)) {
                throw ValidationException::withMessages(['player' => 'Selecione um jogador cadastrado para registrar o pagamento.']);
            }
            $player = collect($state['players'])->firstWhere('id', $input['player']);
            if (($player['position'] ?? 'outfield') === 'goalkeeper') {
                throw ValidationException::withMessages(['player' => 'Goleiros são isentos de pagamento.']);
            }
            $paid = array_values(array_filter($locked->paid_players ?? [], fn (string $id): bool => $id !== $input['player']));
            if ($input['paid']) {
                $paid[] = $input['player'];
            }
            $locked->update(['paid_players' => $paid]);

            return $locked;
        }, 3);

        return response()->json($updated);
    }
}
