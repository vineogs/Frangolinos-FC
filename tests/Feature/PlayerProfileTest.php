<?php

use App\Models\Player;
use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('an absent singleton is initialized and restores players from their table', function () {
    $player = Player::factory()->create();
    DB::table('racha_states')->where('id', 1)->delete();
    $this->getJson('/racha')->assertOk()->assertJsonPath('revision', 0)->assertJsonPath('data.players.0.id', $player->id)->assertJsonPath('data.current', null);
    $this->getJson('/racha')->assertOk()->assertJsonCount(1, 'data.players');
    expect(DB::table('racha_states')->count())->toBe(1);
});

test('a player can be added directly to an empty database and persists in the player table', function () {
    DB::table('racha_states')->where('id', 1)->delete();
    $result = $this->postJson('/players', ['name' => '  Vini  ', 'position' => 'goalkeeper'])->assertCreated()->assertJsonPath('player.name', 'Vini')->assertJsonPath('revision', 1);
    $id = $result->json('player.id');
    $this->assertDatabaseHas('players', ['id' => $id, 'name' => 'Vini', 'position' => 'goalkeeper', 'active' => true]);
    $this->getJson('/racha')->assertOk()->assertJsonPath('data.players.0.id', $id);
    $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->assertCreated()->assertJsonPath('revision', 2)->assertJsonCount(2, 'data.players');
});

test('adding an invalid or duplicate player does not overwrite existing players', function () {
    $this->postJson('/players', ['name' => 'Vini', 'position' => 'outfield'])->assertCreated();
    $this->postJson('/players', ['name' => 'vini', 'position' => 'goalkeeper'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/players', ['name' => 'Ana', 'position' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('position');
    expect(Player::count())->toBe(1);
    $this->getJson('/racha')->assertJsonPath('revision', 1);
});

test('roster edits persist in the player table and a stale edit does not change it', function () {
    $result = $this->postJson('/players', ['name' => 'Vini', 'position' => 'outfield'])->json();
    $result['data']['players'][0]['name'] = 'Vini goleiro';
    $result['data']['players'][0]['position'] = 'goalkeeper';
    $result['data']['players'][0]['active'] = false;
    $this->putJson('/racha', ['revision' => 1, 'data' => $result['data']])->assertOk();
    $this->assertDatabaseHas('players', ['id' => $result['player']['id'], 'name' => 'Vini goleiro', 'position' => 'goalkeeper', 'active' => false]);
    $result['data']['players'][0]['name'] = 'Mudança antiga';
    $this->putJson('/racha', ['revision' => 1, 'data' => $result['data']])->assertConflict();
    expect(Player::first()->name)->toBe('Vini goleiro');
});

test('the selected profile is remembered by the session and can be changed without a password', function () {
    $one = $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->json('player');
    $two = $this->postJson('/players', ['name' => 'Beto', 'position' => 'goalkeeper'])->json('player');
    $this->getJson('/profile')->assertOk()->assertJsonPath('player', null);
    $this->putJson('/profile', ['player' => $one['id']])->assertOk()->assertSessionHas('player_id', $one['id']);
    $this->getJson('/profile')->assertJsonPath('player.id', $one['id']);
    $this->putJson('/profile', ['player' => $two['id']])->assertOk();
    $this->getJson('/profile')->assertJsonPath('player.id', $two['id']);
});

test('personal attendance derives the player from the selected profile and preserves other attendance', function () {
    $one = $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->json('player');
    $two = $this->postJson('/players', ['name' => 'Beto', 'position' => 'outfield'])->json('player');
    $day = RachaDay::factory()->create(['attendees' => [$two['id']]]);
    $url = '/profile/days/'.$day->id.'/attendance';
    $this->putJson($url, ['present' => true])->assertUnprocessable();
    $this->putJson('/profile', ['player' => $one['id']])->assertOk();
    $this->putJson($url, ['present' => true, 'player' => $two['id']])->assertUnprocessable();
    $this->putJson($url, ['present' => true])->assertOk();
    expect($day->fresh()->attendees)->toContain($one['id'], $two['id']);
    $this->putJson($url, ['present' => false])->assertOk()->assertJsonPath('attendees', [$two['id']]);
    $day->update(['finished_at' => now()]);
    $this->putJson($url, ['present' => true])->assertUnprocessable();
});

test('an archived or unknown player cannot be selected as a profile', function () {
    $result = $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->json();
    $this->putJson('/profile', ['player' => $result['player']['id']])->assertOk();
    $result['data']['players'][0]['active'] = false;
    $this->putJson('/racha', ['revision' => 1, 'data' => $result['data']])->assertOk();
    $this->getJson('/profile')->assertJsonPath('player', null);
    $this->putJson('/profile', ['player' => $result['player']['id']])->assertUnprocessable();
    $this->putJson('/profile', ['player' => '10000000-0000-4000-8000-000000000099'])->assertUnprocessable();
});

test('a profile can confirm its own payment without changing attendance or other payments', function () {
    $one = $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->json('player');
    $two = $this->postJson('/players', ['name' => 'Beto', 'position' => 'outfield'])->json('player');
    $day = RachaDay::factory()->create(['attendees' => [$one['id']], 'paid_players' => [$two['id']]]);
    $url = '/profile/days/'.$day->id.'/payments';
    $this->putJson($url, ['paid' => true])->assertUnprocessable();
    $this->putJson('/profile', ['player' => $one['id']])->assertOk();
    $this->putJson($url, ['paid' => true, 'player' => $two['id']])->assertUnprocessable();
    $this->putJson($url, ['paid' => true])->assertOk();
    $this->putJson($url, ['paid' => true])->assertOk();
    expect($day->fresh()->paid_players)->toHaveCount(2)->toContain($one['id'], $two['id']);
    expect($day->fresh()->attendees)->toBe([$one['id']]);
    $this->assertDatabaseHas('racha_states', ['id' => 1, 'revision' => 2]);
});

test('an archived profile cannot record a payment and a payment status is required', function () {
    $result = $this->postJson('/players', ['name' => 'Ana', 'position' => 'outfield'])->json();
    $day = RachaDay::factory()->create();
    $url = '/profile/days/'.$day->id.'/payments';
    $this->putJson('/profile', ['player' => $result['player']['id']])->assertOk();
    $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('paid');
    $result['data']['players'][0]['active'] = false;
    $this->putJson('/racha', ['revision' => 1, 'data' => $result['data']])->assertOk();
    $this->putJson($url, ['paid' => true])->assertUnprocessable()->assertJsonValidationErrors('player');
    expect($day->fresh()->paid_players ?? [])->toBe([]);
});
