<?php

use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function arrivalFixture(int $total = 18): array
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

function storeArrivalState(array $data, int $revision = 0): void
{
    DB::table('racha_states')->where('id', 1)->update(['revision' => $revision, 'data' => json_encode($data)]);
}

test('new days start with registered players separate from arrivals', function () {
    $this->postJson('/days', ['date' => '2026-10-10', 'time' => '19:00', 'end_time' => '21:00', 'location' => 'Quadra'])->assertCreated()->assertJsonPath('arrived_players', []);
});

test('a late arrival joins the bench while an unarrived participant cannot replace a player', function () {
    [$data, $day] = arrivalFixture();
    $playing = array_merge($data['current']['teams']['a'], $data['current']['teams']['b']);
    $day->update(['arrived_players' => $playing]);
    $late = $data['players'][13]['id'];
    $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][3]['id'], 'in' => $late])->assertUnprocessable();

    $response = $this->postJson('/days/'.$day->id.'/arrival', ['revision' => 0, 'player' => $late, 'arrived' => true])->assertOk();
    expect($response->json('data.current.bench'))->toBe([$late]);
    expect($day->fresh()->arrived_players)->toContain($late);
    $this->postJson('/racha/substitutions', ['revision' => 1, 'out' => $data['players'][3]['id'], 'in' => $late])->assertOk();
});

test('arrival requires registration and cannot remove a player currently on the field', function () {
    [$data, $day] = arrivalFixture();
    $day->update(['arrived_players' => array_merge($data['current']['teams']['a'], $data['current']['teams']['b'])]);
    $before = $day->fresh()->arrived_players;
    $this->postJson('/days/'.$day->id.'/arrival', ['revision' => 0, 'player' => 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'arrived' => true])->assertUnprocessable();
    $this->postJson('/days/'.$day->id.'/arrival', ['revision' => 0, 'player' => $data['players'][3]['id'], 'arrived' => false])->assertUnprocessable();
    expect($day->fresh()->arrived_players)->toBe($before);
    $this->assertDatabaseHas('racha_states', ['id' => 1, 'revision' => 0]);
});

test('only arrived players enter the next lineup', function () {
    [$data, $day] = arrivalFixture();
    $day->update(['arrived_players' => array_merge($data['current']['teams']['a'], $data['current']['teams']['b'])]);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk();
    expect($response->json('data.next.bench'))->toBe([]);
    expect(array_merge($response->json('data.next.teams.a'), $response->json('data.next.teams.b')))->toEqualCanonicalizing($day->fresh()->arrived_players);
});

test('three consecutive wins redraw the winner and restart its streak', function () {
    [$data, $day] = arrivalFixture();
    $data['matches'] = [];
    for ($index = 0; $index < 2; $index++) {
        $match = $data['current'];
        $match['id'] = sprintf('a2000000-0000-4000-8000-%012d', $index + 2);
        $match['winner'] = 'a';
        $data['matches'][] = $match;
    }
    storeArrivalState($data);
    $response = $this->postJson('/racha/finish', ['revision' => 0])->assertOk()->assertJsonPath('data.nextMessage', 'Três vitórias seguidas! Os times foram sorteados novamente.');
    foreach (['a', 'b'] as $team) {
        expect(count(array_intersect($response->json('data.next.teams.'.$team), $data['current']['teams']['a'])))->toBeLessThan(5);
        expect($response->json('data.next.teams.'.$team))->toHaveCount(6);
    }
    $next = $this->postJson('/racha/next', ['revision' => 1, 'dayId' => $day->id])->assertOk()->json('data');
    $next['current']['goals'] = [['id' => 'a3000000-0000-4000-8000-000000000009', 'team' => 'a', 'player' => $next['current']['teams']['a'][0], 'time' => 1]];
    storeArrivalState($next, 2);
    $fourth = $this->postJson('/racha/finish', ['revision' => 2])->assertOk();
    expect($fourth->json('data.next.teams.a'))->toBe($next['current']['teams']['a']);
});

test('a dual position player takes priority over an outfield player for a goalkeeper vacancy', function () {
    [$data, $day] = arrivalFixture();
    $data['players'][2]['position'] = 'both';
    storeArrivalState($data);
    $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][0]['id'], 'in' => $data['players'][13]['id']])->assertUnprocessable();
    $this->postJson('/racha/substitutions', ['revision' => 0, 'out' => $data['players'][0]['id'], 'in' => $data['players'][2]['id']])->assertOk()->assertJsonPath('data.current.goalkeepers.a', $data['players'][2]['id']);
});

test('rotation chooses a dual position player when the previous goalkeeper is unavailable', function () {
    [$data, $day] = arrivalFixture();
    $data['players'][2]['position'] = 'both';
    $day->update(['departed' => [$data['players'][1]['id']]]);
    storeArrivalState($data);
    $this->postJson('/racha/finish', ['revision' => 0])->assertOk()->assertJsonPath('data.next.goalkeepers.b', $data['players'][2]['id']);
});
