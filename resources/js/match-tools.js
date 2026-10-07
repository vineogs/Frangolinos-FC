export const cloneState = (state) => JSON.parse(JSON.stringify(state));

export function drawTeams(players, random = Math.random) {
    if (players.length < 12) throw new Error('Confirme a presença de pelo menos 12 jogadores para formar os dois times.');
    const shuffle = (pool) => {
        for (let index = pool.length - 1; index > 0; index--) {
            const other = Math.floor(random() * (index + 1));
            [pool[index], pool[other]] = [pool[other], pool[index]];
        }
        return pool;
    };
    const preferred = shuffle(players.filter((player) => player.position === 'goalkeeper')).slice(0,2);
    const others = shuffle(players.filter((player) => !preferred.includes(player)));
    const keepers = [...preferred, ...others.splice(0,2-preferred.length)];
    const assignments = Object.fromEntries(players.map((player) => [player.id,'bank']));
    keepers.forEach((player,index) => {assignments[player.id] = index === 0 ? 'a':'b';});
    others.slice(0,10).forEach((player,index) => {assignments[player.id] = index % 2 ? 'b':'a';});
    return assignments;
}

export function buildLineup(players, assignments, selectedGoalkeepers = {}) {
    const teams = {a:[], b:[]};
    const bench = [];
    const goalkeepers = {};
    for (const player of players) {
        const team = assignments[player.id];
        if (team === 'a' || team === 'b') teams[team].push(player.id);
        else bench.push(player.id);
    }
    for (const team of ['a','b']) {
        if (teams[team].length !== 6) throw new Error('Cada time deve ter exatamente 5 jogadores de linha e 1 no gol. Ajuste a escalação ou sorteie novamente.');
        const preferred = players.find((player) => teams[team].includes(player.id) && player.position === 'goalkeeper');
        goalkeepers[team] = teams[team].includes(selectedGoalkeepers[team]) ? selectedGoalkeepers[team] : preferred?.id ?? teams[team][0];
    }
    return {teams, bench, goalkeepers};
}

export function remainingSeconds(match, now = Date.now()) {
    if (!match) return 600;
    const elapsed = match.elapsed + (match.startedAt !== null ? Math.max(0, (now - match.startedAt) / 1000) : 0);
    return Math.max(0, Math.ceil(match.duration * 60 - elapsed));
}

export function dailyStatistics(state, day) {
    if (!day) return {matches:0,goals:0,players:[],scorers:[],games:[]};
    if (day.finished_at && day.statistics) return day.statistics;
    const matches = state.matches.filter((match) => match.dayId === day.id);
    const players = state.players.map((player) => {
        let games = 0, wins = 0, goals = 0, assists = 0;
        for (const match of matches) {
            const participation = match.participants ?? match.teams;
            const team = participation.a.includes(player.id) ? 'a' : participation.b.includes(player.id) ? 'b' : null;
            const a = match.goals.filter((goal) => goal.team === 'a').length;
            const b = match.goals.filter((goal) => goal.team === 'b').length;
            const winner = match.winner ?? (a === b ? null : a > b ? 'a' : 'b');
            if (team) {games++; if (team === winner) wins++;}
            goals += match.goals.filter((goal) => goal.player === player.id).length;
            assists += match.goals.filter((goal) => goal.assist === player.id).length;
        }
        return {...player,games,wins,goals,assists,departed:day.departed?.includes(player.id) ?? false};
    }).filter((player) => player.games > 0 || day.attendees.includes(player.id)).sort((a,b) => b.goals - a.goals || b.wins - a.wins || a.name.localeCompare(b.name,'pt-BR'));
    const top = players[0]?.goals ?? 0;
    return {matches:matches.length,goals:matches.reduce((sum,match) => sum + match.goals.length,0),players,scorers:top > 0 ? players.filter((player) => player.goals === top) : [],games:matches};
}
