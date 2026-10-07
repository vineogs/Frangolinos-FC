<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\RachaDay;
use App\Services\RachaStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlayerProfileController extends Controller
{
    public function show(Request $request, RachaStateStore $states): JsonResponse
    {
        $data = json_decode($states->ensure()->data, true);
        $player = collect($data['players'])->firstWhere('id', $request->session()->get('player_id'));

        return response()->json(['player' => ($player['active'] ?? true) ? $player : null]);
    }

    public function update(Request $request, RachaStateStore $states): JsonResponse
    {
        $input = $request->validate(['player' => ['required', 'uuid']]);
        $data = json_decode($states->ensure()->data, true);
        $player = collect($data['players'])->firstWhere('id', $input['player']);
        if (! $player || ($player['active'] ?? true) === false) {
            throw ValidationException::withMessages(['player' => 'Escolha um jogador ativo da lista.']);
        }
        $request->session()->put('player_id', $player['id']);

        return response()->json(['player' => $player]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $player = Player::find($request->session()->get('player_id'));

        return response()->json($player ? $player->notifications()->latest()->limit(50)->get() : []);
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $player = Player::find($request->session()->get('player_id'));
        abort_unless($player, 422);
        $item = $player->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json($item);
    }

    public function attendance(Request $request, RachaDay $day, RachaDayController $days): JsonResponse
    {
        $input = $request->validate(['present' => ['required', 'boolean'], 'player' => ['prohibited']]);
        $player = $request->session()->get('player_id');
        if (! $player) {
            throw ValidationException::withMessages(['player' => 'Escolha seu perfil antes de confirmar presença.']);
        }
        $request->merge(['player' => $player, 'present' => $input['present']]);

        return $days->update($request, $day);
    }

    public function payment(Request $request, RachaDay $day, DayPaymentController $payments, RachaStateStore $states): JsonResponse
    {
        $input = $request->validate(['paid' => ['required', 'boolean'], 'player' => ['prohibited']]);
        $data = json_decode($states->ensure()->data, true);
        $player = collect($data['players'])->firstWhere('id', $request->session()->get('player_id'));
        if (! $player || ($player['active'] ?? true) === false) {
            throw ValidationException::withMessages(['player' => 'Escolha seu perfil antes de confirmar o pagamento.']);
        }
        $request->merge(['player' => $player['id'], 'paid' => $input['paid']]);

        return $payments->update($request, $day);
    }
}
