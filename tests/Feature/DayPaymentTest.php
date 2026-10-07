<?php

use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function paymentFixture(): array
{
    $players = [
        ['id' => '10000000-0000-4000-8000-000000000001', 'name' => 'Ana', 'position' => 'outfield'],
        ['id' => '10000000-0000-4000-8000-000000000002', 'name' => 'Beto', 'position' => 'goalkeeper'],
    ];
    DB::table('racha_states')->where('id', 1)->update(['revision' => 7, 'data' => json_encode(['players' => $players, 'matches' => [], 'current' => null])]);

    return [$players, RachaDay::factory()->create(['attendees' => [$players[0]['id']]])];
}

test('payments are independent from attendance and preserve other payments', function () {
    [$players, $day] = paymentFixture();
    foreach ([$players[0], $players[0], $players[1]] as $player) {
        $this->putJson('/days/'.$day->id.'/payments', ['player' => $player['id'], 'paid' => true])->assertOk();
    }
    expect($day->fresh()->paid_players)->toHaveCount(2);
    expect($day->fresh()->attendees)->toBe([$players[0]['id']]);
    $this->putJson('/days/'.$day->id.'/payments', ['player' => $players[0]['id'], 'paid' => false])->assertOk()->assertJsonPath('paid_players', [$players[1]['id']]);
    $this->assertDatabaseHas('racha_states', ['id' => 1, 'revision' => 7]);
});

test('payments belong to one day and can be corrected after it is closed', function () {
    [$players, $day] = paymentFixture();
    $other = RachaDay::factory()->create();
    $day->update(['finished_at' => now(), 'departed' => [$players[0]['id']]]);
    $this->putJson('/days/'.$day->id.'/payments', ['player' => $players[0]['id'], 'paid' => true])->assertOk();
    expect($other->fresh()->paid_players ?? [])->toBe([]);
    expect($day->fresh()->departed)->toBe([$players[0]['id']]);
    $this->putJson('/days/'.$day->id.'/payments', ['player' => $players[0]['id'], 'paid' => false])->assertOk()->assertJsonCount(0, 'paid_players');
});

test('payment requires a registered player and an explicit valid status', function () {
    [$players, $day] = paymentFixture();
    $this->putJson('/days/'.$day->id.'/payments', ['player' => '10000000-0000-4000-8000-000000000099', 'paid' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
    $this->putJson('/days/'.$day->id.'/payments', ['player' => $players[0]['id']])->assertUnprocessable()->assertJsonValidationErrors('paid');
    expect($day->fresh()->paid_players ?? [])->toBe([]);
});
