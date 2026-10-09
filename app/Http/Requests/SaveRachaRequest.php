<?php

namespace App\Http\Requests;

use App\Models\RachaDay;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class SaveRachaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'revision' => ['required', 'integer', 'min:0'],
            'data' => ['required', 'array:players,matches,current,next,nextMessage'],
            'data.players' => ['present', 'array', 'max:200'],
            'data.players.*' => ['array:id,name,position,active'],
            'data.players.*.id' => ['required', 'uuid', 'distinct'],
            'data.players.*.name' => ['required', 'string', 'max:60'],
            'data.players.*.position' => ['sometimes', 'in:outfield,goalkeeper,both'],
            'data.players.*.active' => ['sometimes', 'boolean'],
            'data.next' => ['sometimes', 'nullable', 'array'],
            'data.nextMessage' => ['sometimes', 'nullable', 'string'],
            'data.matches' => ['present', 'array', 'max:2000'],
            'data.current' => ['present', 'nullable', 'array:id,date,duration,elapsed,startedAt,teams,goals,goalkeepers,dayId,bench,status,winner,reason,participants,substitutions,vacancies,free'],
            'data.matches.*' => ['array:id,date,duration,elapsed,startedAt,teams,goals,goalkeepers,dayId,bench,status,winner,reason,participants,substitutions,vacancies,free'],
        ];
        foreach (['data.current', 'data.matches.*'] as $prefix) {
            $required = $prefix === 'data.current' ? 'required_with:data.current' : 'required';
            $rules[$prefix.'.id'] = [$required, 'uuid'];
            $rules[$prefix.'.free'] = ['sometimes', 'boolean'];
            $rules[$prefix.'.status'] = ['sometimes', 'in:regular,penalties,finished'];
            $rules[$prefix.'.winner'] = ['sometimes', 'nullable', 'in:a,b'];
            $rules[$prefix.'.reason'] = ['sometimes', 'in:goals,time,manual,penalties'];
            $rules[$prefix.'.participants'] = ['sometimes', 'array:a,b'];
            $rules[$prefix.'.participants.a'] = ['present_with:'.$prefix.'.participants', 'array'];
            $rules[$prefix.'.participants.b'] = ['present_with:'.$prefix.'.participants', 'array'];
            $rules[$prefix.'.participants.*.*'] = ['uuid'];
            $rules[$prefix.'.vacancies'] = ['sometimes', 'array'];
            $rules[$prefix.'.vacancies.*'] = ['array:player,team,position'];
            $rules[$prefix.'.vacancies.*.player'] = ['required', 'uuid'];
            $rules[$prefix.'.vacancies.*.team'] = ['required', 'in:a,b'];
            $rules[$prefix.'.vacancies.*.position'] = ['required', 'in:outfield,goalkeeper'];
            $rules[$prefix.'.substitutions'] = ['sometimes', 'array'];
            $rules[$prefix.'.substitutions.*'] = ['array:out,in,team,time,departed,position'];
            $rules[$prefix.'.substitutions.*.out'] = ['required', 'uuid'];
            $rules[$prefix.'.substitutions.*.in'] = ['present', 'nullable', 'uuid'];
            $rules[$prefix.'.substitutions.*.team'] = ['required', 'in:a,b'];
            $rules[$prefix.'.substitutions.*.time'] = ['required', 'numeric', 'min:0', 'max:86400'];
            $rules[$prefix.'.substitutions.*.position'] = ['sometimes', 'in:outfield,goalkeeper'];
            $rules[$prefix.'.substitutions.*.departed'] = ['required', 'boolean'];
            $rules[$prefix.'.dayId'] = ['sometimes', 'nullable', 'uuid', 'exists:racha_days,id'];
            $rules[$prefix.'.bench'] = ['sometimes', 'array', 'max:200'];
            $rules[$prefix.'.bench.*'] = ['required', 'uuid'];
            $rules[$prefix.'.date'] = [$required, 'date'];
            $rules[$prefix.'.duration'] = [$required, 'integer', 'min:1', 'max:180'];
            $rules[$prefix.'.elapsed'] = [$required, 'numeric', 'min:0', 'max:86400'];
            $rules[$prefix.'.startedAt'] = ['present', 'nullable', 'numeric', 'min:0'];
            $rules[$prefix.'.teams'] = [$required, 'array:a,b'];
            $rules[$prefix.'.goalkeepers'] = ['sometimes', 'array:a,b'];
            $rules[$prefix.'.goalkeepers.a'] = ['present_with:'.$prefix.'.goalkeepers', 'nullable', 'uuid'];
            $rules[$prefix.'.goalkeepers.b'] = ['present_with:'.$prefix.'.goalkeepers', 'nullable', 'uuid'];
            foreach (['a', 'b'] as $team) {
                $rules[$prefix.'.teams.'.$team] = ['present', 'array', 'max:100'];
                $rules[$prefix.'.teams.'.$team.'.*'] = ['required', 'uuid'];
            }
            $rules[$prefix.'.goals'] = ['present', 'array', 'max:1000'];
            $rules[$prefix.'.goals.*'] = ['array:id,player,assist,team,time'];
            $rules[$prefix.'.goals.*.id'] = ['required', 'uuid'];
            $rules[$prefix.'.goals.*.player'] = ['required', 'uuid'];
            $rules[$prefix.'.goals.*.assist'] = ['sometimes', 'nullable', 'uuid'];
            $rules[$prefix.'.goals.*.team'] = ['required', 'in:a,b'];
            $rules[$prefix.'.goals.*.time'] = ['required', 'numeric', 'min:0', 'max:86400'];
        }

        foreach ($rules as $key => &$rule) {
            if (str_starts_with($key, 'data.current.')) {
                array_unshift($rule, 'exclude_if:data.current,null');
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $players = array_column($this->input('data.players'), 'id');
            $matches = $this->input('data.matches');
            if ($this->input('data.current')) {
                $matches[] = $this->input('data.current');
            }
            $stored = json_decode(DB::table('racha_states')->where('id', 1)->value('data'), true);
            foreach ($matches as $match) {
                $isCurrent = $match['id'] === ($this->input('data.current.id'));
                $isLegacy = $match['id'] === ($stored['current']['id'] ?? null) && ! isset($stored['current']['goalkeepers']);
                if (isset($match['goalkeepers']) || ($isCurrent && ! $isLegacy)) {
                    if (! in_array($match['duration'], [10, 15], true)) {
                        $validator->errors()->add('data.current.duration', 'Escolha uma partida de 10 ou 15 minutos.');
                    }
                    foreach (['a', 'b'] as $team) {
                        $goalkeeper = $match['goalkeepers'][$team] ?? null;
                        $vacancies = array_values(array_filter($match['vacancies'] ?? [], fn (array $item): bool => $item['team'] === $team));
                        $keeperVacant = count(array_filter($vacancies, fn (array $item): bool => $item['position'] === 'goalkeeper')) === 1;
                        if (count($match['teams'][$team]) + count($vacancies) !== 6 || (! $keeperVacant && ! in_array($goalkeeper, $match['teams'][$team], true))) {
                            $validator->errors()->add('data', 'Cada time precisa de 5 jogadores de linha e 1 goleiro identificado.');
                        }
                        if ($isCurrent) {
                            if ($vacancies !== [] && $match['startedAt'] !== null) {
                                $validator->errors()->add('data', 'Preencha as vagas de substituição antes de retomar o cronômetro.');
                            }
                        }
                    }
                    foreach (['a', 'b'] as $team) {
                        if (! ($match['free'] ?? false) && $match['duration'] === 10 && count(array_filter($match['goals'], fn (array $goal): bool => $goal['team'] === $team)) > 2) {
                            $validator->errors()->add('data', 'A partida termina quando um time faz 2 gols.');
                        }
                    }

                }
                $participants = array_merge($match['teams']['a'], $match['teams']['b']);
                $bench = $match['bench'] ?? [];
                if (count(array_unique($bench)) !== count($bench) || array_intersect($participants, $bench) || array_diff($bench, $players)) {
                    $validator->errors()->add('data', 'O banco deve conter jogadores cadastrados que não estejam nos times.');
                }
                if ($isCurrent && $match['id'] !== ($stored['current']['id'] ?? null) && isset($match['dayId'])) {
                    $day = RachaDay::find($match['dayId']);
                    if ($day->finished_at !== null || array_diff(array_merge($participants, $bench), array_diff(array_intersect($day->attendees, $day->arrived_players ?? $day->attendees), $day->departed ?? []))) {
                        $validator->errors()->add('data', 'Só jogadores inscritos que já chegaram podem entrar no sorteio.');
                    }
                    $active = collect($this->input('data.players'))->filter(fn (array $player): bool => ($player['active'] ?? true) !== false)->pluck('id')->all();
                    if (array_diff(array_merge($participants, $bench), $active)) {
                        $validator->errors()->add('data', 'Jogadores arquivados não podem ser escalados.');
                    }
                }
                if (count(array_unique($participants)) !== count($participants) || array_diff($participants, $players)) {
                    $validator->errors()->add('data', 'Cada jogador deve estar cadastrado e participar de apenas um time.');
                }
                $participation = $match['participants'] ?? $match['teams'];
                $allParticipants = array_merge($participation['a'], $participation['b']);
                if (array_diff($allParticipants, $players) || count(array_unique($allParticipants)) !== count($allParticipants) || array_diff($match['teams']['a'], $participation['a']) || array_diff($match['teams']['b'], $participation['b'])) {
                    $validator->errors()->add('data', 'As participações precisam registrar os jogadores de cada time sem duplicação.');
                }
                if ($isCurrent && ($stored['current']['status'] ?? null) === 'penalties' && (($match['status'] ?? null) !== 'penalties' || $match['startedAt'] !== null || $match['goals'] !== $stored['current']['goals'])) {
                    $validator->errors()->add('data', 'Defina o vencedor dos pênaltis para encerrar a partida.');
                }
                foreach ($match['goals'] as $goal) {
                    $assist = $goal['assist'] ?? null;
                    $newGoal = $isCurrent && ! in_array($goal['id'], array_column($stored['current']['goals'] ?? [], 'id'), true);
                    $eligible = $newGoal ? $match['teams'][$goal['team']] : $participation[$goal['team']];
                    if ($assist !== null && ($assist === $goal['player'] || ! in_array($assist, $eligible, true))) {
                        $validator->errors()->add('data', 'A assistência deve ser de outro jogador do mesmo time, em campo no momento do gol.');
                    }
                    if (! in_array($goal['player'], $participation[$goal['team']], true)) {
                        $validator->errors()->add('data', 'O autor do gol deve participar do time informado.');
                    }
                }
            }
        }];
    }
}
