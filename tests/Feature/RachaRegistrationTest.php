<?php

use App\Models\Player;
use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('a new racha notifies every profile and changes preserve registrations', function () {
    $one = $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->json('player');
    $two = $this->postJson('/players', ['name' => 'Beto', 'position' => 'goalkeeper'])->json('player');
    $schedule = ['date' => '2026-10-10', 'time' => '19:00', 'end_time' => '21:00', 'location' => 'Quadra'];

    $day = $this->postJson('/days', $schedule)->assertCreated()->json();
    expect(DB::table('notifications')->count())->toBe(2);
    $this->putJson('/profile', ['player' => $one['id']])->assertOk();
    $notification = $this->getJson('/profile/notifications')->assertJsonCount(1)->assertJsonPath('0.data.title', 'Novo dia de racha!')->json('0');
    $this->putJson('/profile/notifications/'.$notification['id'].'/read')->assertOk();
    $this->putJson('/profile', ['player' => $two['id']])->assertOk();
    $this->getJson('/profile/notifications')->assertJsonPath('0.read_at', null);
    $this->putJson('/profile/notifications/'.$notification['id'].'/read')->assertNotFound();
    $this->putJson('/profile/days/'.$day['id'].'/attendance', ['present' => true])->assertOk();

    $this->putJson('/days/'.$day['id'].'/schedule', [...$schedule, 'time' => '20:00', 'end_time' => '22:00'])->assertOk()->assertJsonPath('attendees', [$two['id']])->assertJsonPath('end_time', '22:00');
    expect(DB::table('notifications')->count())->toBe(4);
    $this->assertDatabaseHas('racha_days', ['id' => $day['id'], 'time' => '20:00', 'end_time' => '22:00']);
    $this->putJson('/days/'.$day['id'].'/schedule', [...$schedule, 'time' => '20:00', 'end_time' => '22:00'])->assertOk();
    expect(DB::table('notifications')->count())->toBe(4);
});

test('end time is required and must follow start time', function (array $times) {
    $this->postJson('/days', ['date' => '2026-10-10', 'time' => '19:00', 'location' => 'Quadra', ...$times])->assertUnprocessable()->assertJsonValidationErrors('end_time');
    expect(RachaDay::count())->toBe(0);
    expect(DB::table('notifications')->count())->toBe(0);
})->with([[[]], [['end_time' => '18:00']], [['end_time' => '19:00']], [['end_time' => '25:00']]]);

test('a closed day cannot have its schedule changed', function () {
    $day = RachaDay::factory()->create(['finished_at' => now()]);
    $this->putJson('/days/'.$day->id.'/schedule', ['date' => '2026-10-11', 'time' => '20:00', 'end_time' => '22:00', 'location' => 'Outra quadra'])->assertUnprocessable();
    expect($day->fresh()->time)->toBe('19:00');
    expect(DB::table('notifications')->count())->toBe(0);
});

test('registration enforces separate limits and withdrawing releases a vacancy', function (string $position, int $limit) {
    $players = Player::factory()->count($limit + 1)->create(['position' => $position])->toArray();
    DB::table('racha_states')->where('id', 1)->update(['data' => json_encode(['players' => $players, 'matches' => [], 'current' => null])]);
    $day = RachaDay::factory()->create(['attendees' => array_column(array_slice($players, 0, $limit - 1), 'id')]);
    $url = '/days/'.$day->id.'/attendance';
    $last = $players[$limit - 1]['id'];
    $extra = $players[$limit]['id'];

    $this->putJson($url, ['player' => $last, 'present' => true])->assertOk()->assertJsonCount($limit, 'attendees');
    $this->putJson($url, ['player' => $last, 'present' => true])->assertOk()->assertJsonCount($limit, 'attendees');
    $this->putJson('/profile', ['player' => $extra])->assertOk();
    $this->putJson('/profile/days/'.$day->id.'/attendance', ['present' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
    expect($day->fresh()->attendees)->not->toContain($extra);
    $this->putJson($url, ['player' => $last, 'present' => false])->assertOk();
    $this->putJson('/profile/days/'.$day->id.'/attendance', ['present' => true])->assertOk()->assertJsonCount($limit, 'attendees');
    expect($day->fresh()->attendees)->toContain($extra);
})->with([['outfield', 15], ['goalkeeper', 4]]);

test('goalkeepers cannot be charged through either payment endpoint', function () {
    $keeper = $this->postJson('/players', ['name' => 'Goleiro', 'position' => 'goalkeeper'])->json('player');
    $day = RachaDay::factory()->create(['attendees' => [$keeper['id']]]);

    $this->putJson('/days/'.$day->id.'/payments', ['player' => $keeper['id'], 'paid' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
    $this->putJson('/profile', ['player' => $keeper['id']])->assertOk();
    $this->putJson('/profile/days/'.$day->id.'/payments', ['paid' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
    expect($day->fresh()->paid_players ?? [])->toBe([]);
});
