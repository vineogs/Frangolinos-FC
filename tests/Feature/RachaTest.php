<?php

use App\Models\RachaDay;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function rachaFixture(): array
{
    $fixture = [
        'players' => [
            ['id' => 'b68e1c80-43bb-4e8c-b7a2-77d71c4d7591', 'name' => 'Vini', 'position' => 'goalkeeper'],
            ['id' => 'fd66c424-b1f4-4f43-8900-0d79cdb08dde', 'name' => 'Pedro', 'position' => 'goalkeeper'],
        ],
        'matches' => [],
        'current' => [
            'id' => '09d72df9-73c2-4ec7-8ae6-1489ba2c312a',
            'date' => '2026-10-07T15:00:00.000Z',
            'duration' => 10,
            'elapsed' => 125,
            'startedAt' => null,
            'teams' => [
                'a' => ['b68e1c80-43bb-4e8c-b7a2-77d71c4d7591'],
                'b' => ['fd66c424-b1f4-4f43-8900-0d79cdb08dde'],
            ],
            'goalkeepers' => ['a' => 'b68e1c80-43bb-4e8c-b7a2-77d71c4d7591', 'b' => 'fd66c424-b1f4-4f43-8900-0d79cdb08dde'],
            'goals' => [
                ['id' => 'aaf27d84-b0b5-4516-a50e-b9418ec435e8', 'player' => 'b68e1c80-43bb-4e8c-b7a2-77d71c4d7591', 'team' => 'a', 'time' => 120],
            ],
        ],
    ];
    for ($index = 1; $index <= 10; $index++) {
        $id = sprintf('00000000-0000-4000-8000-%012d', $index);
        $fixture['players'][] = ['id' => $id, 'name' => 'Jogador '.$index, 'position' => 'outfield'];
        $fixture['current']['teams'][$index <= 5 ? 'a' : 'b'][] = $id;
    }

    return $fixture;
}

test('a new racha starts without players or matches', function () {
    $this->getJson('/racha')->assertOk()->assertExactJson([
        'revision' => 0, 'data' => ['players' => [], 'matches' => [], 'current' => null],
    ]);
});

test('players can be saved before creating a match', function () {
    $data = rachaFixture();
    $data['current'] = null;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $this->getJson('/racha')->assertJsonPath('data.players.0.name', 'Vini')->assertJsonPath('data.current', null);
});

test('a match preserves its participants goals and timer when reloaded', function () {
    $data = rachaFixture();
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('revision', 1);
    $this->getJson('/racha')->assertExactJson(['revision' => 1, 'data' => $data]);
    $this->assertDatabaseHas('racha_states', ['id' => 1, 'revision' => 1]);
});

test('players can participate in multiple historical matches', function () {
    $data = rachaFixture();
    $data['matches'] = [$data['current'], $data['current']];
    $data['matches'][1]['id'] = 'ab7b36bd-30b5-466f-aee8-62b698568b07';
    $data['current'] = null;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $this->getJson('/racha')->assertJsonCount(2, 'data.matches')->assertJsonPath('data.current', null);
});

test('a stale update returns 409 and preserves the latest data', function () {
    $data = rachaFixture();
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $data['players'][0]['name'] = 'Outro nome';
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertConflict();
    $this->getJson('/racha')->assertJsonPath('data.players.0.name', 'Vini');
});

test('a goal for the wrong team returns 422', function () {
    $data = rachaFixture();
    $data['current']['goals'][0]['team'] = 'b';
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
    $this->assertDatabaseHas('racha_states', ['id' => 1, 'revision' => 0]);
});

test('a player on both teams returns 422', function () {
    $data = rachaFixture();
    $data['current']['teams']['b'][] = $data['players'][0]['id'];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('an unregistered participant returns 422', function () {
    $data = rachaFixture();
    $data['current']['teams']['b'] = ['ab7b36bd-30b5-466f-aee8-62b698568b07'];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('invalid duration and empty player name return 422', function () {
    $data = rachaFixture();
    $data['players'][0]['name'] = '';
    $data['current']['duration'] = 0;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors(['data.players.0.name', 'data.current.duration']);
});

test('a team without five outfield players returns 422', function () {
    $data = rachaFixture();
    array_pop($data['current']['teams']['a']);
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('a registered goalkeeper can occupy an outfield slot', function () {
    $data = rachaFixture();
    $data['players'][2]['position'] = 'goalkeeper';
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
});

test('an outfield player can be assigned as the actual goalkeeper', function () {
    $data = rachaFixture();
    $data['current']['goalkeepers']['a'] = $data['players'][2]['id'];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
});

test('a new match must last exactly ten minutes', function () {
    $data = rachaFixture();
    $data['current']['duration'] = 20;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data.current.duration');
});

test('the second goal automatically archives the match and preserves the goalkeeper', function () {
    $data = rachaFixture();
    $goal = $data['current']['goals'][0];
    $goal['id'] = '78c1bf8a-fd97-4ca3-a974-2397cbbd04d2';
    $goal['time'] = 125;
    $data['current']['goals'][] = $goal;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current', null)->assertJsonCount(2, 'data.matches.0.goals');
    $this->getJson('/racha')->assertJsonPath('data.current', null)->assertJsonPath('data.matches.0.goalkeepers.a', $data['players'][0]['id']);
});

test('three goals for one team return 422', function () {
    $data = rachaFixture();
    $data['current']['goals'] = array_fill(0, 3, $data['current']['goals'][0]);
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('the match is archived at ten minutes even if the page was closed', function () {
    $this->freezeTime();
    $data = rachaFixture();
    $data['current']['elapsed'] = 0;
    $data['current']['startedAt'] = now()->getTimestampMs();
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $this->travel(11)->minutes();
    $this->getJson('/racha')->assertOk()->assertJsonPath('data.current', null)->assertJsonPath('data.matches.0.elapsed', 600)->assertJsonPath('data.matches.0.startedAt', null)->assertJsonPath('revision', 2);
    $this->getJson('/racha')->assertJsonCount(1, 'data.matches')->assertJsonPath('revision', 2);
});

test('a goal beyond ten minutes returns 422', function () {
    $data = rachaFixture();
    $data['current']['goals'][0]['time'] = 601;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('legacy historical matches remain available without goalkeeper metadata', function () {
    $data = rachaFixture();
    $legacy = $data['current'];
    unset($legacy['goalkeepers']);
    $legacy['teams'] = ['a' => [$data['players'][0]['id']], 'b' => [$data['players'][1]['id']]];
    $legacy['duration'] = 20;
    $data['matches'] = [$legacy];
    $data['current'] = null;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $this->getJson('/racha')->assertJsonPath('data.matches.0.duration', 20);
});

test('a one all score does not end the match', function () {
    $data = rachaFixture();
    $data['current']['goals'][] = ['id' => '78c1bf8a-fd97-4ca3-a974-2397cbbd04d2', 'player' => $data['players'][1]['id'], 'team' => 'b', 'time' => 125];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current.id', $data['current']['id'])->assertJsonCount(0, 'data.matches');
});

test('a second goal by the red team also archives the match', function () {
    $data = rachaFixture();
    $data['current']['goals'] = [
        ['id' => '78c1bf8a-fd97-4ca3-a974-2397cbbd04d2', 'player' => $data['players'][1]['id'], 'team' => 'b', 'time' => 100],
        ['id' => 'aaf27d84-b0b5-4516-a50e-b9418ec435e8', 'player' => $data['players'][1]['id'], 'team' => 'b', 'time' => 125],
    ];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current', null)->assertJsonCount(2, 'data.matches.0.goals');
});

test('a scoreless match waits for penalties at exactly ten minutes', function () {
    $data = rachaFixture();
    $data['current']['goals'] = [];
    $data['current']['elapsed'] = 600;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current.status', 'penalties')->assertJsonPath('data.current.elapsed', 600)->assertJsonCount(0, 'data.matches');
});

test('a newly drawn match can be created without goals or elapsed time', function () {
    $data = rachaFixture();
    $data['current']['goals'] = [];
    $data['current']['elapsed'] = 0;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current.elapsed', 0)->assertJsonCount(0, 'data.current.goals');
});

test('a match can include a confirmed bench without counting it as part of a team', function () {
    $data = rachaFixture();
    $data['players'][] = ['id' => 'ab7b36bd-30b5-466f-aee8-62b698568b07', 'name' => 'Reserva', 'position' => 'outfield'];
    $day = RachaDay::factory()->create(['attendees' => array_column($data['players'], 'id')]);
    $data['current']['dayId'] = $day->id;
    $data['current']['bench'] = ['ab7b36bd-30b5-466f-aee8-62b698568b07'];
    $data['current']['goals'] = [];
    $data['current']['elapsed'] = 0;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current.dayId', $day->id)->assertJsonCount(1, 'data.current.bench')->assertJsonCount(6, 'data.current.teams.a');
});

test('an absent player cannot be included in a new match', function () {
    $data = rachaFixture();
    $day = RachaDay::factory()->create(['attendees' => []]);
    $data['current']['dayId'] = $day->id;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('a player cannot be on the bench and on a team simultaneously', function () {
    $data = rachaFixture();
    $data['current']['bench'] = [$data['players'][0]['id']];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('a player in the current match cannot withdraw attendance', function () {
    $data = rachaFixture();
    $day = RachaDay::factory()->create(['attendees' => array_column($data['players'], 'id')]);
    $data['current']['dayId'] = $day->id;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $this->putJson('/days/'.$day->id.'/attendance', ['player' => $data['players'][0]['id'], 'present' => false])->assertUnprocessable()->assertJsonValidationErrors('player');
});

test('a player can be renamed and archived while keeping their historical participation', function () {
    $data = rachaFixture();
    $data['matches'] = [$data['current']];
    $data['current'] = null;
    $data['players'][0]['name'] = 'Vini goleiro';
    $data['players'][0]['active'] = false;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $this->getJson('/racha')->assertJsonPath('data.players.0.name', 'Vini goleiro')->assertJsonPath('data.players.0.active', false)->assertJsonPath('data.matches.0.goalkeepers.a', $data['players'][0]['id']);
});

test('an archived player cannot join a new match even with previous attendance confirmed', function () {
    $data = rachaFixture();
    $day = RachaDay::factory()->create(['attendees' => array_column($data['players'], 'id')]);
    $data['current']['dayId'] = $day->id;
    $data['players'][0]['active'] = false;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
});

test('a teammate assist is saved and survives match completion', function () {
    $data = rachaFixture();
    $assist = $data['current']['teams']['a'][1];
    $data['current']['goals'][0]['assist'] = $assist;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk()->assertJsonPath('data.current.goals.0.assist', $assist);
    $this->postJson('/racha/finish', ['revision' => 1])->assertOk()->assertJsonPath('data.matches.0.goals.0.assist', $assist);
});

test('an assist cannot belong to the scorer or the opposing team', function (string $source) {
    $data = rachaFixture();
    $data['current']['goals'][0]['assist'] = $source === 'scorer' ? $data['current']['goals'][0]['player'] : $data['current']['teams']['b'][0];
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertUnprocessable()->assertJsonValidationErrors('data');
})->with(['scorer', 'opponent']);

test('goals accept an explicitly absent assist and reject an unknown player', function () {
    $data = rachaFixture();
    $data['current']['goals'][0]['assist'] = null;
    $this->putJson('/racha', ['revision' => 0, 'data' => $data])->assertOk();
    $data['current']['goals'][0]['assist'] = '10000000-0000-4000-8000-000000000099';
    $this->putJson('/racha', ['revision' => 1, 'data' => $data])->assertUnprocessable();
});
