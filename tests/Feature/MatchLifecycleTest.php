<?php

use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function lifecycleFixture(int $total = 18): array
{
    $players = [];
    for ($index = 0; $index < $total; $index++) {
        $players[] = ['id' => sprintf('10000000-0000-4000-8000-%012d', $index), 'name' => 'Amigo '.$index, 'position' => $index < 3 ? 'goalkeeper' : 'outfield'];
    }
    $day = RachaDay::factory()->create(['attendees' => array_column($players, 'id')]);
    $teams = ['a' => array_column([$players[0], ...array_slice($players, 3, 5)], 'id'), 'b' => array_column([$players[1], ...array_slice($players, 8, 5)], 'id')];
    $match = ['id' => 'a2000000-0000-4000-8000-000000000001', 'date' => '2026-10-07T16:00:00Z', 'dayId' => $day->id, 'duration' => 10, 'elapsed' => 100, 'startedAt' => null, 'teams' => $teams, 'goalkeepers' => ['a' => $players[0]['id'], 'b' => $players[1]['id']], 'bench' => array_values(array_diff(array_column($players, 'id'), array_merge($teams['a'], $teams['b']))), 'goals' => [['id' => 'a3000000-0000-4000-8000-000000000001', 'team' => 'a', 'player' => $players[3]['id'], 'time' => 90]]];
    $data = ['players' => $players, 'matches' => [], 'current' => $match];
    DB::table('racha_states')->where('id', 1)->update(['revision' => 0, 'data' => json_encode($data)]);

    return [$data, $day];
}

function storeLifecycleState(array $data, int $revision = 0): void
{
    DB::table('racha_states')->where('id', 1)->update(['revision' => $revision, 'data' => json_encode($data)]);
}

test('finishing a match records the winner and prepares the next match on the same day', function () {
    [$data, $day] = lifecycleFixture();
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk()->assertJsonPath('data.current', null)->assertJsonPath('data.matches.0.winner', 'a')->assertJsonPath('data.matches.0.dayId', $day->id)->assertJsonPath('data.next.dayId', $day->id);
    $next = $response->json('data.next');
    expect($next['teams']['a'])->toBe($data['current']['teams']['a']);
    expect(array_intersect($next['teams']['b'], $data['current']['teams']['b']))->toBe([]);
    expect($next['teams']['b'])->toHaveCount(6);
    $prepared = $this->postJson('/racha/next', ['revision' => 1, 'dayId' => $day->id])->assertOk()->assertJsonPath('data.current.id', $next['id'])->assertJsonPath('data.current.elapsed', 0)->assertJsonPath('data.current.startedAt', null);
    expect($prepared->json('data.current.teams'))->toBe($next['teams']);
});

test('a tied match waits for penalties and cannot start another match until a winner is chosen', function () {
    [$data, $day] = lifecycleFixture();
    $data['current']['goals'] = [];
    $data['current']['elapsed'] = 600;
    storeLifecycleState($data);
    $this->getJson('/racha')->assertOk()->assertJsonPath('data.current.status', 'penalties')->assertJsonCount(0, 'data.matches')->assertJsonPath('revision', 1);
    $this->postJson('/racha/next', ['revision' => 1, 'dayId' => $day->id])->assertUnprocessable();
    $result = $this->postJson('/racha/penalties', ['revision' => 1, 'winner' => 'b'])->assertOk()->assertJsonPath('data.current', null)->assertJsonPath('data.matches.0.winner', 'b')->assertJsonPath('data.matches.0.reason', 'penalties')->assertJsonCount(0, 'data.matches.0.goals');
    expect($result->json('data.next.teams.b'))->toBe($data['current']['teams']['b']);
});

test('penalties cannot override a regular winner', function () {
    lifecycleFixture();
    $this->postJson('/racha/penalties', ['revision' => 0, 'winner' => 'b'])->assertUnprocessable()->assertJsonValidationErrors('winner');
});

test('the team with more goals wins automatically at the end of the time', function () {
    [$data] = lifecycleFixture();
    $data['current']['elapsed'] = 600;
    storeLifecycleState($data);
    $this->getJson('/racha')->assertOk()->assertJsonPath('data.current', null)->assertJsonPath('data.matches.0.winner', 'a')->assertJsonPath('data.matches.0.reason', 'time');
});

test('the losing team supplies only the missing slots when the bench is too small', function () {
    [$data, $day] = lifecycleFixture(15);
    $day->update(['attendees' => array_values(array_diff($day->attendees, [$data['players'][2]['id']]))]);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    $loser = $response->json('data.next.teams.b');
    expect($loser)->toContain($data['players'][13]['id'], $data['players'][14]['id']);
    expect(array_intersect($loser, $data['current']['teams']['b']))->toHaveCount(4);
    expect($response->json('data.next.teams.a'))->toBe($data['current']['teams']['a']);
});

test('bank players who played fewer matches take priority over experienced bank players', function () {
    [$data, $day] = lifecycleFixture(20);
    $prior = $data['current'];
    $prior['id'] = 'a2000000-0000-4000-8000-000000000002';
    $prior['winner'] = 'b';
    $prior['participants'] = ['a' => [$data['players'][13]['id'], $data['players'][14]['id']], 'b' => []];
    $data['matches'] = [$prior];
    storeLifecycleState($data);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    $loser = $response->json('data.next.teams.b');
    foreach ([15, 16, 17, 18, 19] as $index) {
        expect($loser)->toContain($data['players'][$index]['id']);
    }
    expect($loser)->not->toContain($data['players'][13]['id'], $data['players'][14]['id']);
});

test('the losing players with fewer games are retained when the bank is insufficient', function () {
    [$data, $day] = lifecycleFixture(15);
    $day->update(['attendees' => array_values(array_diff($day->attendees, [$data['players'][2]['id']]))]);
    $prior = $data['current'];
    $prior['id'] = 'a2000000-0000-4000-8000-000000000002';
    $prior['participants'] = ['a' => [], 'b' => [$data['players'][11]['id'], $data['players'][12]['id']]];
    $prior['winner'] = 'b';
    $data['matches'] = [$prior];
    storeLifecycleState($data);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    $loser = $response->json('data.next.teams.b');
    expect($loser)->toContain($data['players'][8]['id'], $data['players'][9]['id'], $data['players'][10]['id']);
    expect($loser)->not->toContain($data['players'][11]['id'], $data['players'][12]['id']);
});

test('a departed winner is excluded from the next match while their statistics remain', function () {
    [$data, $day] = lifecycleFixture();
    $out = $data['players'][3]['id'];
    $this->postJson('/days/'.$day->id.'/availability', ['revision' => 0, 'player' => $out, 'available' => false, 'replacement' => $data['players'][13]['id']])->assertOk()->assertJsonPath('data.current.goals.0.player', $out);
    $finished = $this->postJson('/racha/finish', ['revision' => 1])->assertOk();
    expect($finished->json('data.matches.0.participants.a'))->toContain($out);
    expect(array_merge($finished->json('data.next.teams.a'), $finished->json('data.next.teams.b'), $finished->json('data.next.bench')))->not->toContain($out);
    expect($day->fresh()->departed)->toContain($out);
    $statistics = $this->getJson('/days/'.$day->id.'/statistics')->assertOk();
    $player = collect($statistics->json('players'))->firstWhere('id', $out);
    expect($player['games'])->toBe(1)->and($player['goals'])->toBe(1)->and($player['departed'])->toBeTrue();
});

test('a departure without replacement pauses the timer and a later substitution fills the vacancy', function () {
    [$data, $day] = lifecycleFixture();
    $this->freezeTime();
    $data['current']['startedAt'] = now()->getTimestampMs();
    storeLifecycleState($data);
    $out = $data['players'][3]['id'];
    $response = $this->postJson('/days/'.$day->id.'/availability', ['revision' => 0, 'player' => $out, 'available' => false])->assertOk()->assertJsonPath('data.current.startedAt', null)->assertJsonCount(5, 'data.current.teams.a')->assertJsonCount(1, 'data.current.vacancies');
    $invalid = $response->json('data');
    $invalid['current']['startedAt'] = now()->getTimestampMs();
    $this->putJson('/racha', ['revision' => 1, 'data' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('data');
    $filled = $this->postJson('/racha/substitutions', ['revision' => 1, 'out' => $out, 'in' => $data['players'][13]['id']])->assertOk()->assertJsonCount(6, 'data.current.teams.a')->assertJsonCount(0, 'data.current.vacancies');
    $valid = $filled->json('data');
    $valid['current']['startedAt'] = now()->getTimestampMs();
    $this->putJson('/racha', ['revision' => 2, 'data' => $valid])->assertOk();
});

test('a preferred goalkeeper on the bench takes priority for a goalkeeper substitution', function () {
    [$data] = lifecycleFixture();
    $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][0]['id'], 'in' => $data['players'][13]['id']])->assertUnprocessable()->assertJsonValidationErrors('replacement');
    $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][0]['id'], 'in' => $data['players'][2]['id']])->assertOk()->assertJsonPath('data.current.goalkeepers.a', $data['players'][2]['id']);
});

test('a normal substitution keeps the outgoing player available for later games', function () {
    [$data, $day] = lifecycleFixture();
    $out = $data['players'][3]['id'];
    $response = $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $out, 'in' => $data['players'][13]['id']])->assertOk();
    expect($response->json('data.current.bench'))->toContain($out);
    expect($day->fresh()->departed ?? [])->toBe([]);
});

test('closing a day creates a final summary across all its games and excludes penalty kicks from goals', function () {
    [$data, $day] = lifecycleFixture();
    $this->postJson('/days/'.$day->id.'/finish', ['revision' => 0])->assertUnprocessable();
    $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    $prepared = $this->postJson('/racha/next', ['revision' => 1, 'dayId' => $day->id])->assertOk();
    $next = $prepared->json('data');
    $next['current']['elapsed'] = 600;
    storeLifecycleState($next, 2);
    $this->getJson('/racha')->assertJsonPath('data.current.status', 'penalties');
    $this->postJson('/racha/penalties', ['revision' => 3, 'winner' => 'b'])->assertOk();
    $this->postJson('/days/'.$day->id.'/finish', ['revision' => 4])->assertOk()->assertJsonPath('data.next', null);
    expect($day->fresh()->finished_at)->not->toBeNull();
    $this->getJson('/days/'.$day->id.'/statistics')->assertOk()->assertJsonPath('matches', 2)->assertJsonPath('goals', 1)->assertJsonPath('scorers.0.id', $data['players'][3]['id']);
    $this->postJson('/racha/next', ['revision' => 5, 'dayId' => $day->id])->assertUnprocessable();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $data['players'][3]['id'], 'present' => true])->assertUnprocessable();
});

test('a returned player can refill their own vacancy without appearing twice or entering the bench', function () {
    [$data, $day] = lifecycleFixture();
    $id = $data['players'][3]['id'];
    $this->postJson('/days/'.$day->id.'/availability', ['revision' => 0, 'player' => $id, 'available' => false])->assertOk();
    $this->postJson('/days/'.$day->id.'/availability', ['revision' => 1, 'player' => $id, 'available' => true])->assertOk();
    $restored = $this->postJson('/racha/substitutions', ['revision' => 2, 'out' => $id, 'in' => $id])->assertOk()->assertJsonCount(6, 'data.current.teams.a')->assertJsonCount(0, 'data.current.vacancies');
    expect($restored->json('data.current.bench'))->not->toContain($id);
    $this->putJson('/racha', ['revision' => 3, 'data' => $restored->json('data')])->assertOk();
});

test('a departed goalkeeper without replacement leaves a goalkeeper vacancy and preserves previous participation', function () {
    [$data, $day] = lifecycleFixture();
    $id = $data['players'][0]['id'];
    $response = $this->postJson('/days/'.$day->id.'/availability', ['revision' => 0, 'player' => $id, 'available' => false])->assertOk()->assertJsonPath('data.current.goalkeepers.a', null)->assertJsonPath('data.current.vacancies.0.position', 'goalkeeper');
    $this->putJson('/racha', ['revision' => 1, 'data' => $response->json('data')])->assertOk();
});

test('a departure with an invalid replacement rolls back both availability and the match', function () {
    [$data, $day] = lifecycleFixture();
    $id = $data['players'][0]['id'];
    $this->postJson('/days/'.$day->id.'/availability', ['revision' => 0, 'player' => $id, 'available' => false, 'replacement' => $data['players'][13]['id']])->assertUnprocessable();
    expect($day->fresh()->departed ?? [])->toBe([]);
    $this->assertDatabaseHas('racha_states', ['revision' => 0]);
});

test('games played on another day do not affect rotation priority for this day', function () {
    [$data, $day] = lifecycleFixture(20);
    $other = RachaDay::factory()->create();
    $prior = $data['current'];
    $prior['dayId'] = $other->id;
    $prior['id'] = 'a2000000-0000-4000-8000-000000000002';
    $prior['winner'] = 'a';
    $prior['participants'] = ['a' => [$data['players'][15]['id']], 'b' => []];
    $data['matches'] = [$prior];
    storeLifecycleState($data);
    $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    $statistics = $this->getJson('/days/'.$day->id.'/statistics')->assertOk()->assertJsonPath('matches', 1);
    $player = collect($statistics->json('players'))->firstWhere('id', $data['players'][15]['id']);
    expect($player['games'])->toBe(0);
});

test('two goals automatically decide the winner without waiting for ten minutes', function () {
    [$data] = lifecycleFixture();
    $goal = $data['current']['goals'][0];
    $goal['id'] = 'a3000000-0000-4000-8000-000000000002';
    $goal['time'] = 100;
    $data['current']['goals'][] = $goal;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current', null)->assertJsonPath('data.matches.0.winner', 'a')->assertJsonPath('data.matches.0.reason', 'goals')->assertJsonPath('data.matches.0.elapsed', 100);
});

test('when nobody is on the bench the previous loser fills all remaining slots', function () {
    [$data, $day] = lifecycleFixture();
    $day->update(['attendees' => array_merge($data['current']['teams']['a'], $data['current']['teams']['b'])]);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    expect($response->json('data.next.teams.a'))->toBe($data['current']['teams']['a']);
    expect($response->json('data.next.teams.b'))->toEqualCanonicalizing($data['current']['teams']['b']);
    expect($response->json('data.next.bench'))->toBe([]);
});

test('legacy results without an explicit winner still keep the team that had more goals', function () {
    [$data, $day] = lifecycleFixture();
    $data['matches'] = [$data['current']];
    $data['current'] = null;
    storeLifecycleState($data);
    $response = $this->postJson('/racha/next', ['revision' => 0, 'dayId' => $day->id])->assertOk();
    expect($response->json('data.current.teams.a'))->toBe($data['matches'][0]['teams']['a']);
});

test('an outfield replacement can keep goal when no preferred goalkeeper is available', function () {
    [$data, $day] = lifecycleFixture();
    $day->update(['departed' => [$data['players'][2]['id']]]);
    $response = $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][0]['id'], 'in' => $data['players'][13]['id']])->assertOk()->assertJsonPath('data.current.goalkeepers.a', $data['players'][13]['id'])->assertJsonPath('data.current.substitutions.0.position', 'goalkeeper');
    $this->putJson('/racha', ['revision' => 1, 'data' => $response->json('data')])->assertOk();
    $this->postJson('/racha/finish', ['revision' => 2])->assertOk()->assertJsonPath('data.next.goalkeepers.a', $data['players'][13]['id']);
});

test('a registered goalkeeper can replace an outfield player without changing the actual goalkeeper', function () {
    [$data] = lifecycleFixture();
    $response = $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][3]['id'], 'in' => $data['players'][2]['id']])->assertOk()->assertJsonPath('data.current.goalkeepers.a', $data['players'][0]['id']);
    $this->putJson('/racha', ['revision' => 1, 'data' => $response->json('data')])->assertOk();
});

test('rotation forms complete teams even when everyone prefers the same position', function (string $position) {
    [$data, $day] = lifecycleFixture();
    foreach ($data['players'] as &$player) {
        $player['position'] = $position;
    }
    unset($player);
    storeLifecycleState($data);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    $next = $response->json('data.next');
    foreach (['a', 'b'] as $team) {
        expect($next['teams'][$team])->toHaveCount(6)->toContain($next['goalkeepers'][$team]);
    }
    $this->postJson('/racha/next', ['revision' => 1, 'dayId' => $day->id])->assertOk();
})->with(['outfield', 'goalkeeper']);

test('assist statistics remain attributed after the passer is substituted and the day closes', function () {
    [$data, $day] = lifecycleFixture();
    $id = $data['players'][4]['id'];
    $data['current']['goals'][0]['assist'] = $id;
    storeLifecycleState($data);
    $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $id, 'in' => $data['players'][13]['id']])->assertOk();
    $this->postJson('/racha/finish', ['revision' => 1])->assertOk();
    $this->postJson('/days/'.$day->id.'/finish', ['revision' => 2])->assertOk();
    $stats = $this->getJson('/days/'.$day->id.'/statistics')->assertOk()->json();
    $player = collect($stats['players'])->firstWhere('id', $id);
    expect($player['assists'])->toBe(1)->and($player['goals'])->toBe(0);
});
