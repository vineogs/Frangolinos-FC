<?php

use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function attendancePlayer(): string
{
    $id = 'b68e1c80-43bb-4e8c-b7a2-77d71c4d7591';
    DB::table('racha_states')->where('id', 1)->update(['data' => json_encode(['players' => [['id' => $id, 'name' => 'Vini', 'position' => 'outfield']], 'matches' => [], 'current' => null])]);

    return $id;
}

test('a day can be scheduled with date time and location', function () {
    $this->postJson('/days', ['date' => '2026-10-10', 'time' => '19:00', 'end_time' => '21:00', 'location' => 'Quadra dos amigos'])->assertCreated()->assertJsonPath('attendees', []);
    $this->assertDatabaseHas('racha_days', ['date' => '2026-10-10', 'time' => '19:00', 'end_time' => '21:00', 'location' => 'Quadra dos amigos']);
    $this->getJson('/days')->assertOk()->assertJsonCount(1);
});

test('invalid date and time return 422', function () {
    $this->postJson('/days', ['date' => 'banana', 'time' => '25:00', 'location' => ''])->assertUnprocessable()->assertJsonValidationErrors(['date', 'time', 'location']);
});

test('a friend can confirm and withdraw attendance for a day', function () {
    $id = attendancePlayer();
    $day = RachaDay::factory()->create();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $id, 'present' => true])->assertOk()->assertJsonPath('attendees', [$id]);
    expect($day->fresh()->attendees)->toBe([$id]);
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $id, 'present' => false])->assertOk()->assertJsonPath('attendees', []);
    expect($day->fresh()->attendees)->toBe([]);
});

test('repeated confirmation does not duplicate a player or change the match revision', function () {
    $id = attendancePlayer();
    $day = RachaDay::factory()->create(['attendees' => [$id]]);
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $id, 'present' => true])->assertOk()->assertJsonPath('attendees', [$id]);
    $this->assertDatabaseHas('racha_states', ['id' => 1, 'revision' => 0]);
});

test('confirmations from different friends preserve both names', function () {
    $first = attendancePlayer();
    $second = 'fd66c424-b1f4-4f43-8900-0d79cdb08dde';
    $state = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);
    $state['players'][] = ['id' => $second, 'name' => 'Pedro'];
    DB::table('racha_states')->where('id', 1)->update(['data' => json_encode($state)]);
    $day = RachaDay::factory()->create();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $first, 'present' => true])->assertOk();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $second, 'present' => true])->assertOk()->assertJsonPath('attendees', [$first, $second]);
});

test('presence on one day does not confirm presence on another', function () {
    $id = attendancePlayer();
    $first = RachaDay::factory()->create();
    $second = RachaDay::factory()->create();
    $this->putJson('/days/'.$first->id.'/attendance', ['player' => $id, 'present' => true])->assertOk();
    expect($second->fresh()->attendees)->toBe([]);
});

test('an unknown player cannot confirm presence', function () {
    $day = RachaDay::factory()->create();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => 'b68e1c80-43bb-4e8c-b7a2-77d71c4d7591', 'present' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
});

test('an archived player cannot confirm presence', function () {
    $id = attendancePlayer();
    $state = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);
    $state['players'][0]['active'] = false;
    DB::table('racha_states')->where('id', 1)->update(['data' => json_encode($state)]);
    $day = RachaDay::factory()->create();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $id, 'present' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
});
