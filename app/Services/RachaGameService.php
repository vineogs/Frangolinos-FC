<?php

namespace App\Services;

use App\Models\RachaDay;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RachaGameService
{
    public function elapsed(array $match): float
    {
        return min($match['duration'] * 60, $match['elapsed'] + ($match['startedAt'] === null ? 0 : max(0, (now()->getTimestampMs() - $match['startedAt']) / 1000)));
    }

    public function score(array $match, string $team): int
    {
        return count(array_filter($match['goals'], fn (array $goal): bool => $goal['team'] === $team));
    }

    public function normalize(array $data): array
    {
        $match = $data['current'];
        if ($match === null || ($match['status'] ?? 'regular') === 'penalties') {
            return $data;
        }
        if ($this->elapsed($match) >= $match['duration'] * 60 || max($this->score($match, 'a'), $this->score($match, 'b')) >= 2) {
            return $this->finish($data);
        }

        return $data;
    }

    public function finish(array $data, ?string $penaltyWinner = null): array
    {
        $match = $data['current'];
        if ($match === null) {
            throw ValidationException::withMessages(['match' => 'Não há partida em andamento.']);
        }
        $a = $this->score($match, 'a');
        $b = $this->score($match, 'b');
        $match['elapsed'] = $this->elapsed($match);
        $match['startedAt'] = null;
        if ($penaltyWinner !== null && (($match['status'] ?? 'regular') !== 'penalties' || $a !== $b)) {
            throw ValidationException::withMessages(['winner' => 'A partida precisa estar aguardando o desempate por pênaltis.']);
        }
        if ($a === $b && $penaltyWinner === null) {
            $match['status'] = 'penalties';
            $data['current'] = $match;

            return $data;
        }
        $match['winner'] = $penaltyWinner ?? ($a > $b ? 'a' : 'b');
        $match['reason'] = $penaltyWinner !== null ? 'penalties' : (max($a, $b) >= 2 ? 'goals' : ($match['elapsed'] >= $match['duration'] * 60 ? 'time' : 'manual'));
        $match['status'] = 'finished';
        $match['participants'] ??= $match['teams'];
        $data['matches'][] = $match;
        $data['current'] = null;
        if (isset($match['dayId'])) {
            $data = $this->refreshNext($data, RachaDay::findOrFail($match['dayId']));
        }

        return $data;
    }

    public function available(array $data, RachaDay $day): array
    {
        return array_values(array_filter($data['players'], fn (array $player): bool => ($player['active'] ?? true) && in_array($player['id'], $day->attendees, true) && ! in_array($player['id'], $day->departed ?? [], true)));
    }

    public function statistics(array $data, RachaDay $day): array
    {
        $matches = array_values(array_filter($data['matches'], fn (array $match): bool => ($match['dayId'] ?? null) === $day->id));
        $players = [];
        foreach ($data['players'] as $player) {
            $games = 0;
            $wins = 0;
            $goals = 0;
            $assists = 0;
            foreach ($matches as $match) {
                $participants = $match['participants'] ?? $match['teams'];
                $team = in_array($player['id'], $participants['a'], true) ? 'a' : (in_array($player['id'], $participants['b'], true) ? 'b' : null);
                if ($team !== null) {
                    $games++;
                    $winner = $match['winner'] ?? ($this->score($match, 'a') === $this->score($match, 'b') ? null : ($this->score($match, 'a') > $this->score($match, 'b') ? 'a' : 'b'));
                    $wins += (int) ($winner === $team);
                }
                $goals += count(array_filter($match['goals'], fn (array $goal): bool => $goal['player'] === $player['id']));
                $assists += count(array_filter($match['goals'], fn (array $goal): bool => ($goal['assist'] ?? null) === $player['id']));
            }
            if ($games > 0 || in_array($player['id'], $day->attendees, true)) {
                $players[] = ['id' => $player['id'], 'name' => $player['name'], 'position' => $player['position'] ?? 'outfield', 'games' => $games, 'wins' => $wins, 'goals' => $goals, 'assists' => $assists, 'departed' => in_array($player['id'], $day->departed ?? [], true)];
            }
        }
        usort($players, fn (array $a, array $b): int => $b['goals'] <=> $a['goals'] ?: $b['wins'] <=> $a['wins'] ?: strcmp($a['name'], $b['name']));
        $goals = array_sum(array_map(fn (array $match): int => count($match['goals']), $matches));
        $top = $players[0]['goals'] ?? 0;

        return ['matches' => count($matches), 'goals' => $goals, 'players' => $players, 'scorers' => $top > 0 ? array_values(array_filter($players, fn (array $player): bool => $player['goals'] === $top)) : [], 'games' => $matches];
    }

    private function prioritize(array $pool, array $games): array
    {
        shuffle($pool);
        usort($pool, fn (array $a, array $b): int => ($games[$a['id']] ?? 0) <=> ($games[$b['id']] ?? 0));

        return $pool;
    }

    public function nextLineup(array $data, RachaDay $day, bool $reusePreview = false): array
    {
        if ($day->finished_at !== null) {
            throw ValidationException::withMessages(['day' => 'Este dia de racha já foi encerrado.']);
        }
        $available = $this->available($data, $day);
        $preview = $data['next'] ?? null;
        if ($reusePreview && ($preview['dayId'] ?? null) === $day->id) {
            $availableIds = array_column($available, 'id');
            $previewIds = array_merge($preview['teams']['a'], $preview['teams']['b'], $preview['bench']);
            sort($availableIds);
            sort($previewIds);
            $valid = $availableIds === $previewIds;
            foreach (['a', 'b'] as $team) {
                $valid = $valid && count($preview['teams'][$team]) === 6 && in_array($preview['goalkeepers'][$team], $preview['teams'][$team], true);
            }
            if ($valid) {
                return $preview;
            }
        }
        $games = array_column($this->statistics($data, $day)['players'], 'games', 'id');
        $matches = array_values(array_filter($data['matches'], fn (array $match): bool => ($match['dayId'] ?? null) === $day->id));
        $last = $matches === [] ? null : $matches[array_key_last($matches)];
        $winner = $last === null ? null : ($last['winner'] ?? ($this->score($last, 'a') === $this->score($last, 'b') ? null : ($this->score($last, 'a') > $this->score($last, 'b') ? 'a' : 'b')));
        $loser = $winner === 'a' ? 'b' : 'a';
        $oldPlayers = $last === null ? [] : array_merge($last['teams']['a'], $last['teams']['b']);
        if (count($available) < 12) {
            throw ValidationException::withMessages(['players' => 'São necessários 12 jogadores disponíveis para formar dois times de 5 na linha e 1 no gol.']);
        }
        $teams = ['a' => [], 'b' => []];
        $goalkeepers = [];
        $used = [];
        $order = $winner === null ? ['a', 'b'] : [$winner, $loser];
        if ($winner !== null) {
            $teams[$winner] = array_values(array_intersect($last['teams'][$winner], array_column($available, 'id')));
            $used = $teams[$winner];
            if (in_array($last['goalkeepers'][$winner] ?? null, $teams[$winner], true)) {
                $goalkeepers[$winner] = $last['goalkeepers'][$winner];
            } else {
                $preferred = array_values(array_filter($available, fn (array $player): bool => ($player['position'] ?? 'outfield') === 'goalkeeper' && in_array($player['id'], $teams[$winner], true)));
                if ($preferred !== []) {
                    $goalkeepers[$winner] = $preferred[0]['id'];
                } elseif (count($teams[$winner]) === 6) {
                    $goalkeepers[$winner] = $teams[$winner][0];
                }
            }
        }
        foreach ($order as $team) {
            if (isset($goalkeepers[$team])) {
                continue;
            }
            $pool = array_values(array_filter($available, fn (array $player): bool => ! in_array($player['id'], $used, true)));
            $previousGoalkeeper = $last['goalkeepers'][$team] ?? null;
            if ($previousGoalkeeper !== null && in_array($previousGoalkeeper, array_column($pool, 'id'), true)) {
                $teams[$team][] = $previousGoalkeeper;
                $goalkeepers[$team] = $previousGoalkeeper;
                $used[] = $previousGoalkeeper;

                continue;
            }
            $bank = array_values(array_filter($pool, fn (array $player): bool => ! in_array($player['id'], $oldPlayers, true)));
            $remaining = array_values(array_filter($pool, fn (array $player): bool => in_array($player['id'], $oldPlayers, true)));
            $preferredBank = array_values(array_filter($bank, fn (array $player): bool => ($player['position'] ?? 'outfield') === 'goalkeeper'));
            $preferredRemaining = array_values(array_filter($remaining, fn (array $player): bool => ($player['position'] ?? 'outfield') === 'goalkeeper'));
            $candidates = array_merge($this->prioritize($preferredBank, $games), $this->prioritize($preferredRemaining, $games), $this->prioritize($bank, $games), $this->prioritize($remaining, $games));
            $goalkeeper = $candidates[0]['id'];
            $teams[$team][] = $goalkeeper;
            $goalkeepers[$team] = $goalkeeper;
            $used[] = $goalkeeper;
        }
        foreach ($order as $team) {
            $pool = array_values(array_filter($available, fn (array $player): bool => ! in_array($player['id'], $used, true)));
            $bank = array_values(array_filter($pool, fn (array $player): bool => ! in_array($player['id'], $oldPlayers, true)));
            $remaining = array_values(array_filter($pool, fn (array $player): bool => in_array($player['id'], $oldPlayers, true)));
            $candidates = array_merge($this->prioritize($bank, $games), $this->prioritize($remaining, $games));
            foreach (array_slice($candidates, 0, 6 - count($teams[$team])) as $player) {
                $teams[$team][] = $player['id'];
                $used[] = $player['id'];
            }
        }
        $bench = array_column($this->prioritize(array_values(array_filter($available, fn (array $player): bool => ! in_array($player['id'], $used, true))), $games), 'id');

        return ['id' => (string) Str::uuid(), 'date' => now()->toIso8601String(), 'duration' => 10, 'elapsed' => 0, 'startedAt' => null, 'dayId' => $day->id, 'teams' => $teams, 'goalkeepers' => $goalkeepers, 'bench' => $bench, 'goals' => [], 'status' => 'regular'];
    }

    public function refreshNext(array $data, RachaDay $day): array
    {
        if ($data['current'] !== null) {
            return $data;
        }
        try {
            $data['next'] = $this->nextLineup($data, $day);
            $data['nextMessage'] = null;
        } catch (ValidationException $exception) {
            $data['next'] = null;
            $data['nextMessage'] = collect($exception->errors())->flatten()->first();
        }

        return $data;
    }

    public function substitute(array $data, string $outgoing, ?string $incoming, bool $departing, RachaDay $day): array
    {
        $match = $data['current'];
        if ($match === null || ($match['dayId'] ?? null) !== $day->id) {
            return $this->refreshNext($data, $day);
        }
        if (($match['status'] ?? 'regular') === 'penalties' && (! $departing || $incoming !== null)) {
            throw ValidationException::withMessages(['match' => 'Defina o vencedor dos pênaltis antes de fazer substituições.']);
        }
        $team = null;
        foreach (['a', 'b'] as $candidate) {
            if (in_array($outgoing, $match['teams'][$candidate], true)) {
                $team = $candidate;
            }
        }
        $vacancy = collect($match['vacancies'] ?? [])->firstWhere('player', $outgoing);
        $team ??= $vacancy['team'] ?? null;
        if ($team === null) {
            if ($incoming !== null) {
                throw ValidationException::withMessages(['player' => 'O jogador a substituir precisa estar em campo.']);
            }
            $match['bench'] = array_values(array_filter($match['bench'] ?? [], fn (string $id): bool => $id !== $outgoing));
            $data['current'] = $match;

            return $data;
        }
        $players = collect($data['players'])->keyBy('id');
        $position = $vacancy['position'] ?? (($match['goalkeepers'][$team] ?? null) === $outgoing ? 'goalkeeper' : 'outfield');
        if ($incoming !== null) {
            $available = array_column($this->available($data, $day), 'id');
            $playing = array_merge($match['teams']['a'], $match['teams']['b']);
            $otherTeam = $team === 'a' ? 'b' : 'a';
            if (! in_array($incoming, $available, true) || in_array($incoming, $playing, true) || in_array($incoming, $match['participants'][$otherTeam] ?? [], true)) {
                throw ValidationException::withMessages(['replacement' => 'Escolha um jogador disponível, fora de campo e que não tenha jogado no outro time nesta partida.']);
            }
        }
        if ($incoming !== null && $position === 'goalkeeper' && ($players[$incoming]['position'] ?? 'outfield') !== 'goalkeeper') {
            $preferred = array_filter($this->available($data, $day), fn (array $player): bool => ($player['position'] ?? 'outfield') === 'goalkeeper' && ! in_array($player['id'], $playing, true) && ! in_array($player['id'], $match['participants'][$otherTeam] ?? [], true));
            if ($preferred !== []) {
                throw ValidationException::withMessages(['replacement' => 'Há um goleiro disponível no banco. Dê prioridade a ele para assumir o gol.']);
            }
        }
        $match['participants'] ??= $match['teams'];
        if ($match['elapsed'] <= 0 && $match['startedAt'] === null) {
            $match['participants'][$team] = array_values(array_filter($match['participants'][$team], fn (string $id): bool => $id !== $outgoing));
        }
        $match['teams'][$team] = array_values(array_filter($match['teams'][$team], fn (string $id): bool => $id !== $outgoing));
        $match['bench'] = array_values(array_filter($match['bench'] ?? [], fn (string $id): bool => ! in_array($id, [$outgoing, $incoming], true)));
        $match['vacancies'] = array_values(array_filter($match['vacancies'] ?? [], fn (array $item): bool => $item['player'] !== $outgoing));
        if ($incoming !== null) {
            $match['teams'][$team][] = $incoming;
            $match['participants'][$team] = array_values(array_unique([...$match['participants'][$team], $incoming]));
            if (! $departing && $outgoing !== $incoming && in_array($outgoing, $available, true)) {
                $match['bench'][] = $outgoing;
            }
        } else {
            $match['elapsed'] = $this->elapsed($match);
            $match['startedAt'] = null;
            $match['vacancies'][] = ['player' => $outgoing, 'team' => $team, 'position' => $position];
        }
        if ($position === 'goalkeeper') {
            $match['goalkeepers'][$team] = $incoming;
        }
        $match['substitutions'][] = ['out' => $outgoing, 'in' => $incoming, 'team' => $team, 'time' => $this->elapsed($match), 'departed' => $departing, 'position' => $position];
        $data['current'] = $match;

        return $data;
    }
}
