import {cloneState, drawTeams, buildLineup, remainingSeconds, dailyStatistics} from './match-tools.js';
const $ = (id) => document.getElementById(id);
const escapeHtml = (text) => String(text).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const uid = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
};
const time = (seconds) => `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`;
const date = (value) => new Date(value).toLocaleDateString('pt-BR', {day:'2-digit', month:'short', year:'numeric'});
let state = {players:[], matches:[], current:null};
let revision = 0;
let busy = true;
let loaded = false;
let conflicted = false;
let retryAfter = 0;
let days = [];
let profileNotifications = [];
let editingDayId = null;
let selectedDayId = new URLSearchParams(location.search).get('day');
let setupPlayers = [];
let setupAssignments = {};
let setupGoalkeepers = {};
let editingPlayerId = null;
let openingSetup = false;
let polling = false;
let departureDayId = null;
let goalTeam = null;
let noticeTimer = null;
let activeTab = 'profile';
const todayKey = () => new Date().toLocaleDateString('en-CA', {timeZone:'America/Fortaleza'});
const needsSchedule = () => !state.current && (!selectedDay() || Boolean(selectedDay().finished_at) || (activeTab === 'match' && selectedDay().date !== todayKey()));
let profilePlayerId = null;
try {profilePlayerId = localStorage.getItem('frangolinos-player');} catch {}
const selectedDay = () => days.find((day) => day.id === selectedDayId);
const activePlayers = () => state.players.filter((player) => player.active !== false);
const confirmedPlayers = () => activePlayers().filter((player) => selectedDay()?.attendees.includes(player.id) && !selectedDay()?.departed?.includes(player.id));
const dayLabel = (day) => `${date(`${day.date}T12:00:00`)} das ${day.time}${day.end_time ? ` às ${day.end_time}` : ''}`;
const isPlaying = (id) => Boolean(state.current && [...state.current.teams.a,...state.current.teams.b].includes(id));
const playerName = (id) => state.players.find((player) => player.id === id)?.name ?? 'Jogador';
const elapsed = () => state.current ? Math.min(state.current.duration * 60, state.current.elapsed + (state.current.startedAt ? Math.max(0, (Date.now() - state.current.startedAt) / 1000) : 0)) : 0;
const score = (match, team) => match?.goals.filter((goal) => goal.team === team).length ?? 0;
const empty = (message) => `<div class="empty"><span class="empty-icon">◎</span>${message}</div>`;
function notice(message) {
    clearTimeout(noticeTimer);
    noticeTimer = null;
    $('notice-message').textContent = message;
    $('notice').hidden = !message;
    document.querySelectorAll('.dialog-notice').forEach((element) => {
        const show = Boolean(message && element.closest('dialog')?.open);
        element.textContent = show ? message : '';
        element.hidden = !show;
    });
    if (message) noticeTimer = setTimeout(() => notice(''), Math.min(12000, Math.max(6000, message.length * 55)));
}
$('dismiss-notice').addEventListener('click', () => notice(''));
function allMatches() { return [...state.matches, ...(state.current ? [state.current] : [])]; }
function rankings() {
    return state.players.map((player) => ({...player, goals:allMatches().reduce((total, match) => total + match.goals.filter((goal) => goal.player === player.id).length, 0), assists:allMatches().reduce((total, match) => total + match.goals.filter((goal) => goal.assist === player.id).length, 0), games:allMatches().filter((match) => [...(match.participants?.a ?? match.teams.a), ...(match.participants?.b ?? match.teams.b)].includes(player.id)).length})).sort((a,b) => b.goals - a.goals || a.name.localeCompare(b.name, 'pt-BR'));
}
async function requestJson(url, options = {}) {
    const response = await fetch(url, {...options, headers:{'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content, ...options.headers}});
    let result;
    try { result = await response.json(); } catch { throw new Error('O servidor retornou uma resposta inválida. Recarregue a página e tente novamente.'); }
    if (!response.ok) {
        if (response.status === 419) throw new Error('A sessão expirou. Recarregue a página para continuar.');
        if (response.status === 409) {conflicted = true; throw new Error(result.message);}
        const errors = [...new Set(Object.values(result.errors ?? {}).flat())];
        throw new Error(errors.length ? errors.join(' ') : result.message ?? 'Não foi possível salvar. Tente novamente.');
    }
    return result;
}
async function mutate(action) {
    if (busy || !loaded || conflicted) {
        notice(conflicted ? 'Os dados mudaram em outro dispositivo. Recarregue a página antes de continuar.' : 'Aguarde o carregamento ou a operação atual terminar.');
        return false;
    }
    busy = true;
    const previous = cloneState(state);
    try {
        action();
        render();
        $('save-status').textContent = 'Salvando…';
        const result = await requestJson('/racha', {method:'PUT', body:JSON.stringify({revision, data:state})});
        revision = result.revision;
        state = result.data;
        retryAfter = 0;
        notice('');
        handleTransition(previous.current);
        return true;
    } catch(error) {
        state = previous;
        retryAfter = Date.now() + 5000;
        notice(error.message);
        return false;
    } finally {
        busy = false;
        render();
        $('save-status').textContent = conflicted ? 'Recarregue a página' : retryAfter ? 'Falha ao salvar' : '✓ Tudo salvo';
    }
}
function handleTransition(previous) {
    if (state.current?.status === 'penalties' && previous?.status !== 'penalties') {
        $('goal-dialog').close(); $('confirm-dialog').close();
        $('penalties-dialog').showModal();
        notice('Empate! Defina o vencedor da disputa de pênaltis.');
    }
    if (previous && !state.current) {
        document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
        const match = state.matches.at(-1);
        if (match.dayId) selectedDayId = match.dayId;
        openTab('match');
        renderNext();
        const nextCard = $('next-match-card');
        nextCard.focus({preventScroll:true});
        const reducedMotion = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
        nextCard.scrollIntoView({behavior:reducedMotion ? 'auto' : 'smooth',block:'start'});
        const winner = match.winner ?? (score(match,'a') > score(match,'b') ? 'a' : 'b');
        notice(`Partida registrada! Time ${winner === 'a' ? 'Azul' : 'Vermelho'} venceu${match.reason === 'penalties' ? ' nos pênaltis' : ''}. ${state.next ? 'A próxima escalação está preparada abaixo.' : state.nextMessage ?? 'Prepare a próxima partida quando os jogadores estiverem prontos.'}`);
    }
}
async function lifecycle(url, input = {}) {
    if (busy || !loaded || conflicted) {notice('Aguarde a operação atual ou recarregue os dados antes de continuar.'); return false;}
    busy = true;
    const previous = cloneState(state.current);
    render();
    try {
        const result = await requestJson(url, {method:'POST',body:JSON.stringify({revision,...input})});
        state = result.data; revision = result.revision;
        retryAfter = 0;
        notice('');
        try {days = await requestJson('/days');} catch {}
        handleTransition(previous);
        return true;
    } catch(error) {retryAfter = Date.now()+5000; notice(error.message); return false;}
    finally {busy = false; render(); $('save-status').textContent = retryAfter ? 'Falha ao salvar' : '✓ Tudo salvo';}
}
function renderClock() {
    const match = state.current;
    const seconds = elapsed();
    $('clock').textContent = time(remainingSeconds(match));
    if (match && seconds >= match.duration * 60) $('add-goal').disabled = true;
    $('clock-caption').textContent = !match ? 'Pronto para mais uma partida de 10 minutos' : match.status === 'penalties' ? 'Tempo encerrado · defina o vencedor nos pênaltis' : match.vacancies?.length ? 'Partida pausada · preencha as vagas de substituição' : `${time(seconds)} jogados · 2 gols para vencer`;
}
function render() {
    const match = state.current;
    const ranking = rankings();
    const leader = ranking[0]?.goals > 0 ? ranking[0] : null;
    const leaders = leader ? ranking.filter((player) => player.goals === leader.goals) : [];
    $('nav-count').textContent = state.players.length;
    $('stat-players').textContent = state.players.length;
    $('stat-matches').textContent = state.matches.length;
    $('stat-goals').textContent = allMatches().reduce((total, item) => total + item.goals.length, 0);
    $('stat-leader').textContent = leaders.length > 1 ? `${leaders.length} empatados` : leader?.name ?? '—';
    $('stat-leader-goals').textContent = leader ? `${leader.goals} ${leader.goals === 1 ? 'gol' : 'gols'} na conta` : 'O primeiro gol pode ser seu';
    $('score-a').textContent = score(match, 'a');
    $('score-b').textContent = score(match, 'b');
    $('team-a-count').textContent = match?.goalkeepers ? '5 de linha + 1 goleiro' : `${match?.teams.a.length ?? 0} jogadores`;
    $('team-b-count').textContent = match?.goalkeepers ? '5 de linha + 1 goleiro' : `${match?.teams.b.length ?? 0} jogadores`;
    const dayMatches = state.matches.filter((item) => item.dayId === (match?.dayId ?? selectedDayId));
    $('match-number').textContent = `PARTIDA ${String(dayMatches.length + 1).padStart(2,'0')} DO DIA`;
    $('match-status').textContent = match?.status === 'penalties' ? '● DECISÃO NOS PÊNALTIS' : match?.startedAt ? '● BOLA ROLANDO' : match && match.elapsed > 0 ? '● PARTIDA PAUSADA' : '● AGUARDANDO O APITO';
    $('toggle-clock').textContent = match?.startedAt ? 'Ⅱ Pausar partida' : match?.elapsed > 0 ? '▶ Retomar partida' : '▶ Iniciar partida';
    renderDays();
    renderProfile();
    renderNext();
    $('create-match').disabled = busy || !loaded || conflicted;
    $('create-match').textContent = busy ? 'Salvando…' : 'Criar partida';
    $('new-match').disabled = busy || !loaded || conflicted || Boolean(match);
    $('toggle-clock').disabled = busy || !match || conflicted || match.status === 'penalties' || Boolean(match.vacancies?.length);
    $('add-goal').disabled = busy || !match || conflicted || match.status === 'penalties' || Boolean(match.vacancies?.length) || elapsed() >= match.duration * 60 || (!match.startedAt && match.elapsed === 0);
    $('finish-match').disabled = busy || !match || conflicted || match.status === 'penalties';
    $('penalties-card').hidden = match?.status !== 'penalties';
    $('events-count').textContent = `${match?.goals.length ?? 0} gols`;
    $('events').innerHTML = match?.goals.length ? [...match.goals].reverse().map((goal) => `<div class="event-row"><span class="event-time">${time(goal.time)}</span><span>⚽</span><div class="event-text">${escapeHtml(playerName(goal.player))}<small>${goal.assist ? `Passe de ${escapeHtml(playerName(goal.assist))} · ` : ''}Gol do Time ${goal.team === 'a' ? 'Azul' : 'Vermelho'}</small></div><button class="text-button" ${busy || match.status === 'penalties' ? 'disabled' : ''} data-remove-goal="${goal.id}" aria-label="Desfazer gol de ${escapeHtml(playerName(goal.player))}">Desfazer</button></div>`).join('') : empty('Nenhum gol por aqui ainda.<br>Quando a rede balançar, registre o lance.');
    $('lineup').innerHTML = match ? ['a','b'].map((team) => `<div class="lineup-title ${team === 'b' ? 'red-text' : ''}"><span>● Time ${team === 'a' ? 'Azul' : 'Vermelho'}</span><span>${match.teams[team].length}</span></div><label class="keeper-picker">No gol nesta partida<select data-match-keeper="${team}" ${busy || match.status === 'penalties' ? 'disabled' : ''}>${!match.goalkeepers?.[team] ? '<option value="">Escolha quem assume o gol</option>' : ''}${match.teams[team].map((id) => `<option value="${id}" ${id === match.goalkeepers?.[team] ? 'selected' : ''}>${escapeHtml(playerName(id))}</option>`).join('')}</select></label>${[...match.teams[team]].sort((a,b) => Number(b === match.goalkeepers?.[team]) - Number(a === match.goalkeepers?.[team])).map((id) => `<div class="player-row"><span class="avatar">${escapeHtml(playerName(id).slice(0,2).toUpperCase())}</span><span>${escapeHtml(playerName(id))}${id === match.goalkeepers?.[team] ? '<span class="goalkeeper-badge">Goleiro</span>' : ''}</span><small>${match.goals.filter((goal) => goal.player === id).length || '—'} ⚽</small>${match.dayId ? `<button class="manage-player" data-manage-player="${id}" ${busy ? 'disabled' : ''}>Substituir / sair</button>` : ''}</div>`).join('')}`).join('') : empty('Os times ainda não foram escalados.<br>Crie uma partida para entrar em campo.');
    $('vacancies-list').innerHTML = (match?.vacancies ?? []).map((vacancy) => `<div class="vacancy-row">Vaga no Time ${vacancy.team === 'a' ? 'Azul' : 'Vermelho'} · ${vacancy.position === 'goalkeeper' ? 'Goleiro' : 'Linha'}<button class="text-button" data-manage-player="${vacancy.player}" data-vacancy="true">Preencher vaga</button></div>`).join('');
    const displayedPlayers = state.players.filter((player) => ($('show-archived').checked || player.active !== false) && player.name.toLocaleLowerCase('pt-BR').includes($('player-search').value.toLocaleLowerCase('pt-BR')));
    $('players-list').innerHTML = displayedPlayers.length ? displayedPlayers.map((player) => {
        const stats = ranking.find((item) => item.id === player.id);
        return `<div class="player-row ${player.active === false ? 'archived' : ''}"><span class="avatar">${escapeHtml(player.name.slice(0,2).toUpperCase())}</span><span>${escapeHtml(player.name)}</span><div class="player-position-controls"><small>${stats.games} jogos · ${stats.goals} gols</small><select class="position-select" data-position-player="${player.id}" aria-label="Posição de ${escapeHtml(player.name)}" ${busy || !loaded || conflicted || (match && [...match.teams.a,...match.teams.b].includes(player.id)) ? 'disabled' : ''}><option value="outfield" ${player.position !== 'goalkeeper' ? 'selected' : ''}>Jogador de linha</option><option value="goalkeeper" ${player.position === 'goalkeeper' ? 'selected' : ''}>Goleiro</option></select></div><button class="text-button" data-edit-player="${player.id}">Editar</button><button class="text-button" data-archive-player="${player.id}" ${isPlaying(player.id) || busy ? 'disabled' : ''}>${player.active === false ? 'Reativar' : 'Arquivar'}</button></div>`;
    }).join('') : empty('A turma ainda não chegou.<br>Adicione o primeiro jogador acima.');
    const matchDay = days.find((day) => day.id === match?.dayId);
    $('departed-lineup').innerHTML = match && matchDay?.departed?.length ? `<div class="lineup-title bank-text">Fora do racha hoje</div>${matchDay.departed.map((id) => `<div class="player-row"><span>${escapeHtml(playerName(id))}</span><button class="text-button" data-return-player="${id}" data-return-day="${matchDay.id}" ${busy ? 'disabled' : ''}>Retornar ao banco</button></div>`).join('')}` : '';
    $('bench-lineup').innerHTML = match?.bench?.length ? `<div class="lineup-title bank-text">Banco · ${match.bench.length}</div>${match.bench.map((id) => `<div class="player-row"><span class="avatar">${escapeHtml(playerName(id).slice(0,2).toUpperCase())}</span><span>${escapeHtml(playerName(id))}</span><small>${state.players.find((player) => player.id === id)?.position === 'goalkeeper' ? 'Goleiro' : 'Linha'}</small></div>`).join('')}` : '';
    let rank = 0;
    $('ranking-list').innerHTML = ranking.length ? '<div class="ranking-row table-header"><span>#</span><span>JOGADOR</span><span>JOGOS</span><span>ASSIST.</span><span>GOLS</span></div>' + ranking.map((player, index) => {
        if (index === 0 || player.goals !== ranking[index-1].goals) rank = index + 1;
        return `<div class="ranking-row"><span class="rank">${rank}</span><span>${escapeHtml(player.name)}</span><span>${player.games}</span><span>${player.assists ?? 0}</span><strong>${player.goals}</strong></div>`;
    }).join('') : empty('O ranking começa com os jogadores.<br>Cadastre os amigos e bora jogar!');
    $('history-list').innerHTML = state.matches.length ? [...state.matches].reverse().map((item) => `<details class="history-card"><summary><span>${date(item.date)} · ${time(item.elapsed)}</span><strong>Azul ${score(item,'a')} × ${score(item,'b')} Vermelho</strong><span class="muted">Ver lances ↓</span></summary><div class="history-detail"><p><strong>${item.winner ? `Time ${item.winner === 'a' ? 'Azul' : 'Vermelho'} venceu` : score(item,'a') === score(item,'b') ? 'Empate' : `Time ${score(item,'a') > score(item,'b') ? 'Azul' : 'Vermelho'} venceu`}</strong> · ${item.reason === 'penalties' ? 'Decisão nos pênaltis' : Math.max(score(item,'a'),score(item,'b')) >= 2 ? '2 gols marcados' : item.elapsed >= item.duration * 60 ? 'Tempo encerrado' : 'Encerrada manualmente'}</p><p>Time Azul: ${(item.participants?.a ?? item.teams.a).map((id) => escapeHtml(playerName(id)) + (id === item.goalkeepers?.a ? ' (goleiro)' : '')).join(', ')}<br>Time Vermelho: ${(item.participants?.b ?? item.teams.b).map((id) => escapeHtml(playerName(id)) + (id === item.goalkeepers?.b ? ' (goleiro)' : '')).join(', ')}</p>${item.goals.length ? item.goals.map((goal) => `<div>⚽ ${time(goal.time)} · ${escapeHtml(playerName(goal.player))}${goal.assist ? ` · Passe de ${escapeHtml(playerName(goal.assist))}` : ''} · Time ${goal.team === 'a' ? 'Azul' : 'Vermelho'}</div>`).join('') : 'A rede não balançou: jogo sem gols.'}</div></details>`).join('') : empty('Toda resenha tem uma história.<br>As partidas finalizadas vão aparecer aqui.');
    renderClock();
    if ($('goal-dialog').open) renderGoalPicker();
}
const tabs = {profile:['Meu perfil','Sua presença e seus números, em um só lugar.'], days:['Dias de racha','Marque a data, confirme a presença e organize a turma.'], match:['Central da partida','A bola rola. A gente cuida dos números.'], players:['Jogadores','Os amigos que fazem o nosso futebol acontecer.'], ranking:['Artilharia','Quem está mandando mais bolas pra rede?'], history:['Histórico','O placar passa. A resenha fica.']};
function openTab(tab) {
    notice('');
    activeTab = tab;
    if (tab === 'match' && !state.current) {
        const today = days.find((day) => day.date === todayKey() && !day.finished_at);
        if (today) selectedDayId = today.id;
    }
    document.querySelectorAll('.tab').forEach((element) => {element.hidden = element.id !== `tab-${tab}`;});
    document.querySelectorAll('.nav-item').forEach((element) => element.classList.toggle('active', element.dataset.tab === tab));
    $('new-match').hidden = tab === 'profile';
    $('page-title').textContent = tabs[tab][0];
    $('page-subtitle').textContent = tabs[tab][1];
    render();
}
document.addEventListener('click', async (event) => {
    const target = event.target.closest('button');
    if (!target) return;
    if (target.dataset.tab) openTab(target.dataset.tab);
    if (target.dataset.goalTeam && !busy) {goalTeam = target.dataset.goalTeam; $('goal-player').value = ''; $('goal-assist').value = ''; renderGoalPicker();}
    if (target.dataset.goalScorer && !busy) {$('goal-player').value = target.dataset.goalScorer; $('goal-assist').value = ''; renderGoalPicker();}
    if (target.dataset.goalAssist !== undefined && !busy) {$('goal-assist').value = target.dataset.goalAssist; renderGoalPicker();}

    if (target.dataset.close) $(target.dataset.close).close();
    if (target.dataset.removeGoal) await mutate(() => {state.current.goals = state.current.goals.filter((goal) => goal.id !== target.dataset.removeGoal);});
    if (target.dataset.archivePlayer) await mutate(() => {
        const player = state.players.find((item) => item.id === target.dataset.archivePlayer);
        player.active = player.active === false;
    });
    if (target.dataset.editPlayer) {
        editingPlayerId = target.dataset.editPlayer;
        const player = state.players.find((item) => item.id === editingPlayerId);
        $('edit-player-name').value = player.name;
        $('edit-player-position').value = player.position ?? 'outfield';
        $('edit-player-position').disabled = isPlaying(player.id);
        notice('');
        $('edit-player-dialog').showModal();
    }
    if (target.dataset.chooseDay) {selectedDayId = target.dataset.chooseDay; render();}
    if (target.dataset.penaltyWinner) await lifecycle('/racha/penalties', {winner:target.dataset.penaltyWinner});
    if (target.dataset.managePlayer) openSubstitution(target.dataset.managePlayer, target.dataset.vacancy === 'true');
    if (target.dataset.returnPlayer) {
        if (await lifecycle(`/days/${target.dataset.returnDay ?? state.current?.dayId}/availability`, {player:target.dataset.returnPlayer,available:true})) notice('Jogador disponível novamente para este dia.');
    }
    if (target.dataset.myPayment) await setMyPayment(target.dataset.myPayment);
    if (target.dataset.myAttendance) await setMyAttendance(target.dataset.myAttendance, target.dataset.present === 'true');
    if (target.dataset.paymentPlayer) await setPayment(target.dataset.paymentPlayer, target.dataset.paid === 'true');
    if (target.dataset.attendancePlayer) await setAttendance(target.dataset.attendancePlayer, target.dataset.present === 'true');
});
function renderSetup() {
    const groups = Object.fromEntries(['a','b','bank'].map((team) => [team,setupPlayers.filter((player) => (setupAssignments[player.id] ?? 'bank') === team)]));
    for (const team of ['a','b']) {
        if (!groups[team].some((player) => player.id === setupGoalkeepers[team])) setupGoalkeepers[team] = groups[team].find((player) => player.position === 'goalkeeper')?.id ?? groups[team][0]?.id;
    }
    $('setup-counts').innerHTML = `<div class="setup-counts">${['a','b'].map((team) => `<span><b>Time ${team === 'a' ? 'Azul' : 'Vermelho'}</b>${Math.max(0,groups[team].length - (setupGoalkeepers[team] ? 1 : 0))}/5 na linha · ${setupGoalkeepers[team] ? '1' : '0'}/1 no gol</span>`).join('')}</div>`;
    $('setup-players').innerHTML = ['a','b','bank'].map((team) => {
        const players = groups[team].sort((a,b) => Number(b.id === setupGoalkeepers[team]) - Number(a.id === setupGoalkeepers[team]));
        return `<section class="draw-group team-${team === 'a' ? 'blue' : team === 'b' ? 'red' : 'bank'}"><h3>${team === 'a' ? 'Time Azul' : team === 'b' ? 'Time Vermelho' : 'Banco'} · ${players.length}</h3>${team !== 'bank' ? `<label class="keeper-picker">Quem fica no gol?<select data-setup-keeper="${team}" ${players.length ? '' : 'disabled'}>${players.map((player) => `<option value="${player.id}" ${setupGoalkeepers[team] === player.id ? 'selected' : ''}>${escapeHtml(player.name)}${player.position === 'goalkeeper' ? ' · Prefere gol' : ''}</option>`).join('')}</select></label>` : ''}${players.map((player) => `<div class="draw-player"><span>${escapeHtml(player.name)} ${team !== 'bank' && player.id === setupGoalkeepers[team] ? '<span class="goalkeeper-badge">No gol</span>' : `<small>${team === 'bank' ? 'Banco' : 'Na linha'}</small>`}${player.position === 'goalkeeper' && player.id !== setupGoalkeepers[team] ? '<small> · Prefere gol</small>' : ''}</span></div>`).join('') || '<p class="muted">Nenhum jogador</p>'}</section>`;
    }).join('');
}
document.addEventListener('change', async (event) => {
    if (event.target.dataset.setupKeeper) {setupGoalkeepers[event.target.dataset.setupKeeper] = event.target.value; renderSetup();}
    if (event.target.dataset.matchKeeper) {
        const team = event.target.dataset.matchKeeper;
        const id = event.target.value;
        await mutate(() => {
            state.current.goalkeepers[team] = id;
            state.current.vacancies = (state.current.vacancies ?? []).map((vacancy) => vacancy.team === team && vacancy.position === 'goalkeeper' ? {...vacancy,position:'outfield'} : vacancy);
        });
    }
    if (event.target.dataset.positionPlayer) await mutate(() => {
        state.players.find((player) => player.id === event.target.dataset.positionPlayer).position = event.target.value;
    });
});
$('player-search').addEventListener('input', render);
$('show-archived').addEventListener('change', render);
$('edit-player-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = $('edit-player-name').value.trim();
    if (!name) {notice('Informe o nome do jogador.'); return;}
    if (state.players.some((player) => player.id !== editingPlayerId && player.name.toLocaleLowerCase('pt-BR') === name.toLocaleLowerCase('pt-BR'))) {notice('Esse nome já está cadastrado. Use outro apelido.'); return;}
    const position = $('edit-player-position').value;
    if (await mutate(() => {const player = state.players.find((item) => item.id === editingPlayerId); player.name = name; if (!isPlaying(player.id)) player.position = position;})) $('edit-player-dialog').close();
});
$('player-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (await addPlayer($('player-name').value.trim(), $('player-position').value)) $('player-name').value = '';
});
async function openSetup() {
    if (busy || !loaded || state.current || openingSetup) {notice('Finalize a partida atual ou aguarde o carregamento.'); return;}
    openingSetup = true;
    try {
        await refreshDays();
        if (selectedDay()?.finished_at) {notice('Este dia já foi encerrado. Marque outro dia para jogar.'); return;}
        if (state.matches.some((match) => match.dayId === selectedDayId)) {await lifecycle('/racha/next', {dayId:selectedDayId}); openTab('match'); return;}
        if (!selectedDay()) {openTab('days'); notice('Marque ou escolha um dia de racha antes de criar a partida.'); return;}
        setupPlayers = cloneState(confirmedPlayers());
        setupAssignments = drawTeams(setupPlayers); setupGoalkeepers = {};
        notice(''); renderSetup(); $('setup-dialog').showModal();
    } catch (error) {openTab('days'); notice(error.message);}
    finally {openingSetup = false;}
}
$('new-match').addEventListener('click', () => {
    if (busy || !loaded) return;
    if (needsSchedule()) {
        openTab('days');
        $('day-form').scrollIntoView({behavior:'smooth',block:'center'});
        $('day-date').focus({preventScroll:true});
        return;
    }
    return openSetup();
});
$('draw-day').addEventListener('click', openSetup);
$('balance-teams').addEventListener('click', () => {
    try {setupAssignments = drawTeams(setupPlayers); setupGoalkeepers = {}; notice(''); renderSetup();} catch(error) {notice(error.message);}
});
$('setup-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        const lineup = buildLineup(setupPlayers, setupAssignments, setupGoalkeepers);
        const dayId = selectedDayId;
        if (await mutate(() => {
            state.current = {id:uid(),date:new Date().toISOString(),duration:10,elapsed:0,startedAt:null,...lineup,dayId,goals:[]};
        })) { $('setup-dialog').close(); openTab('match'); notice('Partida criada! Clique em Iniciar partida para começar os 10 minutos.'); }
    } catch(error) {notice(error.message);}
});
$('toggle-clock').addEventListener('click', () => mutate(() => {
    if (state.current.startedAt) {state.current.elapsed = Math.min(86400, elapsed()); state.current.startedAt = null;} else {state.current.startedAt = Date.now();}
}));
$('add-goal').addEventListener('click', () => {
    if (!state.current || busy) return;
    goalTeam = null; $('goal-player').value = ''; $('goal-assist').value = '';
    notice(''); renderGoalPicker(); $('goal-dialog').showModal();
});
function renderGoalPicker() {
    const match = state.current;
    const scorer = $('goal-player').value;
    const assist = $('goal-assist').value;
    const ids = match && goalTeam ? match.teams[goalTeam] : [];
    for (const team of ['a','b']) {
        const button = $(`goal-team-${team}`);
        button.classList.toggle('selected', goalTeam === team);
        button.setAttribute('aria-pressed', String(goalTeam === team));
        button.disabled = busy;
    }
    const playerButton = (id, field, selected) => `<button type="button" class="goal-player-button ${selected ? 'selected' : ''}" data-${field}="${id}" aria-pressed="${selected}" ${busy ? 'disabled' : ''}><span class="avatar">${escapeHtml(playerName(id).slice(0,2).toUpperCase())}</span><span>${escapeHtml(playerName(id))}</span>${selected ? '<b aria-hidden="true">✓</b>' : ''}</button>`;
    $('goal-scorers').innerHTML = ids.length ? ids.map((id) => playerButton(id,'goal-scorer',id === scorer)).join('') : '<p class="muted goal-picker-hint">Toque em Azul ou Vermelho para ver os jogadores.</p>';
    $('goal-assist-section').hidden = !scorer;
    $('goal-assist-options').innerHTML = scorer ? `<button type="button" class="goal-player-button ${!assist ? 'selected' : ''}" data-goal-assist="" aria-pressed="${!assist}" ${busy ? 'disabled' : ''}>Sem assistência ${!assist ? '✓' : ''}</button>` + ids.filter((id) => id !== scorer).map((id) => playerButton(id,'goal-assist',id === assist)).join('') : '';
    $('goal-preview').hidden = !scorer;
    $('goal-preview').innerHTML = scorer ? `<strong>⚽ ${escapeHtml(playerName(scorer))}</strong><span>${assist ? `Passe de ${escapeHtml(playerName(assist))}` : 'Sem assistência'} · Time ${goalTeam === 'a' ? 'Azul' : 'Vermelho'}</span>` : '';
    $('confirm-goal').disabled = busy || !scorer;
    $('confirm-goal').textContent = busy ? 'Registrando…' : '⚽ Registrar gol';
}
$('goal-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!state.current) {$('goal-dialog').close(); return;}
    const player = $('goal-player').value, assist = $('goal-assist').value || null;
    if (!goalTeam || !state.current.teams[goalTeam].includes(player) || (assist && (assist === player || !state.current.teams[goalTeam].includes(assist)))) {notice('Escolha o autor do gol e um passe de outro jogador do mesmo time.'); return;}
    if (state.current.status === 'penalties' || state.current.vacancies?.length || (!state.current.startedAt && !state.current.elapsed)) {notice('Inicie ou retome a partida antes de registrar o gol.'); return;}
    if (elapsed() >= state.current.duration * 60) {await mutate(() => {}); return;}
    if (await mutate(() => {state.current.goals.push({id:uid(),player,assist,team:goalTeam,time:Math.min(600, elapsed())});})) {$('goal-dialog').close(); if (state.current) notice('Gol registrado!');}
});
$('finish-match').addEventListener('click', () => $('confirm-dialog').showModal());
$('confirm-finish').addEventListener('click', async () => {await lifecycle('/racha/finish');});
$('open-penalties').addEventListener('click', () => $('penalties-dialog').showModal());
$('prepare-next').addEventListener('click', async () => {if (await lifecycle('/racha/next',{dayId:selectedDayId})) openTab('match');});
function renderNext() {
    const day = selectedDay();
    const last = day ? state.matches.filter((match) => match.dayId === day.id).at(-1) : null;
    const next = state.next?.dayId === day?.id ? state.next : null;
    $('next-match-card').hidden = Boolean(state.current) || !last || Boolean(day?.finished_at);
    $('last-result-title').textContent = last ? `Time ${(last.winner ?? (score(last,'a') > score(last,'b') ? 'a' : 'b')) === 'a' ? 'Azul' : 'Vermelho'} venceu${last.reason === 'penalties' ? ' nos pênaltis' : ''} · próxima partida` : 'Próxima partida';
    $('rotation-info').textContent = next ? 'O vencedor permanece. O banco entra primeiro; as vagas restantes são sorteadas entre quem perdeu. Quem jogou menos tem prioridade, com sorteio nos empates.' : state.nextMessage ?? 'A próxima escalação será montada com os jogadores disponíveis.';
    $('next-lineup').innerHTML = next ? ['a','b','bank'].map((team) => {
        const ids = team === 'bank' ? next.bench : next.teams[team];
        return `<div class="draw-group team-${team === 'a' ? 'blue' : team === 'b' ? 'red' : 'bank'}"><h3>${team === 'a' ? 'Time Azul' : team === 'b' ? 'Time Vermelho' : 'Banco'}</h3>${ids.map((id) => `<div class="draw-player">${escapeHtml(playerName(id))}${id === next.goalkeepers.a || id === next.goalkeepers.b ? '<small>Goleiro</small>' : ''}</div>`).join('') || '<p class="muted">Sem jogadores</p>'}</div>`;
    }).join('') : '';
    $('prepare-next').disabled = busy || !day || Boolean(day.finished_at);
    $('new-match').textContent = needsSchedule() ? '＋ Marcar racha' : last ? '＋ Próxima partida' : '＋ Nova partida';
}
function renderDayStatistics() {
    const day = selectedDay();
    const stats = dailyStatistics(state,day);
    const topFive = stats.players.filter((player) => player.goals > 0).slice(0,5);
    const expanded = new Set([...document.querySelectorAll('[data-day-match][open]')].map((element) => element.dataset.dayMatch));
    const leader = stats.scorers.length > 2 ? `${stats.scorers.length} empatados` : stats.scorers.map((player) => escapeHtml(player.name)).join(', ') || '—';
    $('day-statistics-status').textContent = day?.finished_at ? 'RESUMO FINAL' : 'ACOMPANHAMENTO DO DIA';
    $('day-statistics').innerHTML = day ? `<div class="day-summary"><div>Partidas registradas<strong>${stats.matches}</strong></div><div>Gols no dia<strong>${stats.goals}</strong></div><div>Artilheiro${stats.scorers.length > 1 ? 's' : ''}<strong>${leader}</strong></div></div><h3 class="stats-section-title">Top 5 artilheiros do dia</h3>${topFive.length ? `<div class="day-player-row table-header"><span>JOGADOR</span><span>JOGOS</span><span>VITÓRIAS</span><span>GOLS</span></div>${topFive.map((player) => `<div class="day-player-row"><span>${escapeHtml(player.name)}${player.departed ? ' · Saiu' : ''}</span><span>${player.games}</span><span>${player.wins}</span><strong>${player.goals}</strong></div>`).join('')}` : '<p class="muted">Nenhum gol registrado neste dia.</p>'}<h3 class="stats-section-title">Partidas do dia · clique para ver os detalhes</h3>${stats.games.map((match,index) => {
        const participants = match.participants ?? match.teams;
        const substitutions = match.substitutions ?? [];
        return `<details class="history-card day-match-detail" data-day-match="${match.id}" ${expanded.has(match.id) ? 'open' : ''}><summary><span>Partida ${index+1}</span><strong>Azul ${score(match,'a')} × ${score(match,'b')} Vermelho</strong><span class="muted">Detalhes ▾</span></summary><div class="history-detail"><p><strong>${match.winner ? `Time ${match.winner === 'a' ? 'Azul' : 'Vermelho'} venceu${match.reason === 'penalties' ? ' nos pênaltis' : ''}` : 'Resultado registrado'}</strong> · ${time(match.elapsed)} jogados · ${match.goals.length} gols · ${substitutions.length} substituições / saídas</p><div class="match-team-stats">${['a','b'].map((team) => `<section class="draw-group team-${team === 'a' ? 'blue' : 'red'}"><h3>Time ${team === 'a' ? 'Azul' : 'Vermelho'} · ${score(match,team)} gols</h3>${participants[team].map((id) => {
            const goals = match.goals.filter((goal) => goal.player === id).length;
            const assists = match.goals.filter((goal) => goal.assist === id).length;
            const keeper = id === match.goalkeepers?.[team] || substitutions.some((item) => item.team === team && item.out === id && item.position === 'goalkeeper');
            return `<div class="match-player-stat"><span>${escapeHtml(playerName(id))}${keeper ? ' · No gol' : ''}</span><strong>${goals} ⚽ · ${assists} assist.</strong></div>`;
        }).join('')}</section>`).join('')}</div>${match.goals.map((goal) => `<div class="match-event-stat">⚽ ${time(goal.time)} · ${escapeHtml(playerName(goal.player))}${goal.assist ? ` · Passe de ${escapeHtml(playerName(goal.assist))}` : ''} · Time ${goal.team === 'a' ? 'Azul' : 'Vermelho'}</div>`).join('')}${substitutions.map((item) => `<div class="match-event-stat">⇄ ${time(item.time)} · ${escapeHtml(playerName(item.out))} → ${item.in ? escapeHtml(playerName(item.in)) : 'Vaga aberta'}${item.departed ? ' · Saiu do racha' : ''}</div>`).join('')}</div></details>`;
    }).join('') || '<p class="muted">As partidas finalizadas aparecem aqui.</p>'}` : empty('Selecione um dia para acompanhar todas as partidas.');
}
function renderDays() {
    const day = selectedDay();
    if (!$('share-url').hidden) {$('share-url').value = generalLink();}
    $('active-day-title').textContent = day ? `Racha · ${dayLabel(day)}` : 'Escolha o dia do racha';
    $('active-day-info').textContent = day ? `${day.location} · ${confirmedPlayers().length} presenças confirmadas` : 'Confirme as presenças antes de sortear os times.';
    $('days-list').innerHTML = days.length ? days.map((item) => `<button class="day-choice ${item.id === selectedDayId ? 'selected' : ''}" data-choose-day="${item.id}"><strong>${dayLabel(item)}</strong><small>${escapeHtml(item.location)} · ${item.attendees.length} confirmados</small></button>`).join('') : empty('Nenhum racha marcado.<br>Escolha a data, o horário e o local acima.');
    $('attendance-title').textContent = day ? `Presenças · ${date(`${day.date}T12:00:00`)}` : 'Lista de presença';
    renderDayStatistics();
    $('finish-day').disabled = busy || !loaded || !day || Boolean(day.finished_at) || state.current?.dayId === day.id;
    $('finish-day-central').hidden = !day || Boolean(day.finished_at);
    $('finish-day-central').disabled = $('finish-day').disabled;
    $('edit-day').disabled = busy || !day || Boolean(day.finished_at);
    $('attendance-form').hidden = !day || Boolean(day.finished_at);
    $('attendance-details').innerHTML = day ? `<p class="day-attendance-summary">${escapeHtml(day.location)} · ${escapeHtml(dayLabel(day))}<br><strong>${day.attendees.filter((id) => state.players.find((player) => player.id === id)?.position !== 'goalkeeper').length}/15 jogadores de linha · ${day.attendees.filter((id) => state.players.find((player) => player.id === id)?.position === 'goalkeeper').length}/4 goleiros confirmados</strong></p>` : empty('Escolha um dia marcado para confirmar a presença.');
    const identity = $('attendance-player').value;
    $('attendance-player').innerHTML = '<option value="">Selecione seu nome</option>' + state.players.filter((player) => player.active !== false || day?.attendees.includes(player.id)).map((player) => `<option value="${player.id}">${escapeHtml(player.name)}${player.position === 'goalkeeper' ? ' · Goleiro' : ''}</option>`).join('');
    $('attendance-player').value = identity;
    renderPaymentSummary();
    $('attendance-list').innerHTML = day ? state.players.filter((player) => day.attendees.includes(player.id)).map((player) => {
        const present = day.attendees.includes(player.id);
        const departed = day.departed?.includes(player.id);
        const paid = day.paid_players?.includes(player.id) ?? false;
        const locked = busy || Boolean(day.finished_at) || departed || (isPlaying(player.id) && state.current?.dayId === day.id);
        return `<div class="attendance-row ${departed ? 'departed-player' : ''}"><div class="attendance-identity"><span class="attendance-avatar" aria-hidden="true">${escapeHtml(player.name.slice(0,2).toUpperCase())}</span><div><strong>${escapeHtml(player.name)}</strong><small>${departed ? 'Saiu do racha' : player.position === 'goalkeeper' ? 'Prefere jogar no gol' : 'Jogador de linha'}</small></div></div><button class="presence-button ${present && !departed ? 'presence-confirmed' : 'presence-unconfirmed'}" data-attendance-player="${player.id}" data-present="${!present}" ${locked ? 'disabled' : ''} aria-label="${present ? 'Retirar' : 'Confirmar'} presença de ${escapeHtml(player.name)}">${departed ? 'Saiu do racha' : present ? '✓ Confirmado' : '＋ Confirmar'}</button>${player.position === 'goalkeeper' ? '<span class="payment-not-applicable">Isento</span>' : present || paid ? `<button class="payment-button ${paid ? 'payment-paid' : 'payment-pending'}" data-payment-player="${player.id}" data-paid="${!paid}" ${busy ? 'disabled' : ''} aria-label="${paid ? 'Desmarcar' : 'Marcar'} pagamento de ${escapeHtml(player.name)}">${paid ? '✓ Pago' : 'Marcar pago'}</button>` : '<span class="payment-not-applicable" aria-label="Confirme a presença antes de marcar o pagamento">—</span>'}</div>`;
    }).join('') || empty('Nenhum jogador inscrito. Confirme sua presença pelo perfil ou adicione um jogador acima.') : '';
    if (day && $('attendance-list').innerHTML.includes('attendance-row')) $('attendance-list').innerHTML = '<div class="attendance-table-heading"><span>JOGADOR</span><span>PRESENÇA</span><span>PAGAMENTO</span></div>' + $('attendance-list').innerHTML;
    $('share-day').disabled = !day || busy;
    $('draw-day').disabled = !day || busy || Boolean(state.current) || Boolean(day.finished_at);
    $('confirm-attendance').disabled = busy || Boolean(day?.finished_at);
    $('cancel-attendance').disabled = busy || Boolean(day?.finished_at);
}
function renderPaymentSummary() {
    const day = selectedDay();
    const stats = dailyStatistics(state,day);
    const owed = new Set([...(day?.attendees ?? []), ...stats.players.filter((player) => player.games > 0).map((player) => player.id)].filter((id) => state.players.find((player) => player.id === id)?.position !== 'goalkeeper'));
    const paid = [...owed].filter((id) => day?.paid_players?.includes(id)).length;
    $('payment-summary').innerHTML = day ? `<div><strong>Pagamentos do dia</strong><small>${owed.size} jogadores de linha · goleiros isentos</small></div><div class="payment-totals"><span class="paid-total"><b>${paid}</b> pagos</span><span class="pending-total"><b>${owed.size - paid}</b> pendentes</span></div>` : '';
    $('payment-summary').hidden = !day;
}
async function setPayment(player, paid) {
    if (busy || !selectedDay()) return;
    busy = true;
    const dayId = selectedDayId;
    render();
    try {
        const result = await requestJson(`/days/${dayId}/payments`,{method:'PUT',body:JSON.stringify({player,paid})});
        days = days.map((day) => day.id === result.id ? result : day);
        notice(paid ? 'Pagamento registrado para este dia.' : 'Pagamento marcado como pendente.');
    } catch(error) {notice(error.message);}
    finally {busy = false; render();}
}
async function refreshDays() {
    days = await requestJson('/days');
    if (!selectedDayId && days.length) {
        const today = new Date(); today.setHours(0,0,0,0);
        selectedDayId = (days.find((day) => new Date(`${day.date}T12:00:00`) >= today) ?? days.at(-1)).id;
    }
    renderDays();
}
async function setAttendance(player, present) {
    if (busy) return;
    if (!player || !selectedDay()) {notice('Selecione seu nome e um dia de racha.'); return;}
    busy = true;
    const dayId = selectedDayId;
    render();
    try {
        const result = await requestJson(`/days/${dayId}/attendance`, {method:'PUT', body:JSON.stringify({player,present})});
        days = days.map((day) => day.id === result.id ? result : day);
        notice(present ? 'Presença confirmada!' : 'Presença retirada.');
    } catch(error) {notice(error.message);}
    finally {busy = false; render();}
}
$('attendance-form').addEventListener('submit', (event) => {event.preventDefault(); setAttendance($('attendance-player').value, true);});
$('cancel-attendance').addEventListener('click', () => setAttendance($('attendance-player').value, false));
$('day-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    const data = {date:$('day-date').value,time:$('day-time').value,end_time:$('day-end-time').value,location:$('day-location').value.trim()};
    render();
    try {const changed = Boolean(editingDayId); const day = await requestJson(changed ? `/days/${editingDayId}/schedule` : '/days', {method:changed ? 'PUT' : 'POST',body:JSON.stringify(data)}); selectedDayId = day.id; resetDayForm(); await refreshDays(); await refreshNotifications(); notice(changed ? 'Racha atualizado! Os perfis receberam uma notificação.' : 'Racha marcado! Os perfis receberam uma notificação para se inscrever.');}
    catch(error) {notice(error.message);}
    finally {busy = false; render();}
});
$('share-day').addEventListener('click', () => copyGeneralLink('share-url'));
$('share-profile').addEventListener('click', () => copyGeneralLink('profile-share-url'));
function openSubstitution(playerId, vacancy = false) {
    departureDayId = isPlaying(playerId) || vacancy ? state.current?.dayId : selectedDayId;
    const day = days.find((item) => item.id === departureDayId);
    if (!day || day.finished_at) {notice('Escolha um dia de racha ainda aberto.'); return;}
    const players = state.players.filter((player) => day.attendees.includes(player.id));
    $('outgoing-player').innerHTML = players.map((player) => `<option value="${player.id}">${escapeHtml(player.name)}${player.position === 'goalkeeper' ? ' · Goleiro' : ''}</option>`).join('');
    $('outgoing-player').value = playerId;
    $('depart-player').checked = !vacancy;
    updateReplacementOptions();
    notice('');
    $('substitution-dialog').showModal();
}
function updateReplacementOptions() {
    const outgoing = $('outgoing-player').value;
    const day = days.find((item) => item.id === departureDayId);
    const match = state.current?.dayId === departureDayId ? state.current : null;
    const vacancy = match?.vacancies?.find((item) => item.player === outgoing);
    const position = vacancy?.position ?? (Object.values(match?.goalkeepers ?? {}).includes(outgoing) ? 'goalkeeper' : 'outfield');
    const playing = match ? [...match.teams.a,...match.teams.b] : [];
    const field = playing.includes(outgoing) || vacancy;
    const stats = dailyStatistics(state,day);
    const ownTeam = match && match.teams.a.includes(outgoing) ? 'a' : vacancy?.team ?? 'b';
    const otherTeam = ownTeam === 'a' ? 'b' : 'a';
    const eligible = state.players.filter((player) => (player.id !== outgoing || Boolean(vacancy)) && player.active !== false && day?.attendees.includes(player.id) && !day.departed?.includes(player.id) && !playing.includes(player.id) && !(match?.participants?.[otherTeam] ?? []).includes(player.id));
    const preferred = eligible.filter((player) => player.position === 'goalkeeper');
    const candidates = (position === 'goalkeeper' && preferred.length ? preferred : eligible).sort((a,b) => (stats.players.find((item) => item.id === a.id)?.games ?? 0) - (stats.players.find((item) => item.id === b.id)?.games ?? 0));
    $('incoming-player').disabled = !field;
    $('incoming-player').innerHTML = `<option value="">${field ? 'Sem substituto · pausar a partida' : 'Jogador fora de campo · retirar do dia'}</option>` + (field ? candidates.map((player) => `<option value="${player.id}">${escapeHtml(player.name)} · ${stats.players.find((item) => item.id === player.id)?.games ?? 0} partidas</option>`).join('') : '');
}
$('outgoing-player').addEventListener('change', updateReplacementOptions);
$('substitution-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const outgoing = $('outgoing-player').value;
    const incoming = $('incoming-player').disabled ? null : $('incoming-player').value || null;
    const departing = $('depart-player').checked;
    if (!departing && !incoming) {notice('Escolha quem entra, ou marque a opção de retirar o jogador dos próximos sorteios.'); return;}
    const success = departing ? await lifecycle(`/days/${departureDayId}/availability`,{player:outgoing,available:false,replacement:incoming}) : await lifecycle('/racha/substitutions',{out:outgoing,in:incoming});
    if (success) {$('substitution-dialog').close(); notice(departing ? 'Saída registrada. O jogador mantém suas estatísticas e fica fora dos próximos sorteios deste dia.' : 'Substituição registrada. A participação dos dois jogadores fica no histórico.');}
});
function openFinishDay() {
    const day = selectedDay();
    if (busy || !loaded || !day || day.finished_at || state.current?.dayId === day.id) return;
    notice(''); $('finish-day-dialog').showModal();
}
$('finish-day').addEventListener('click', openFinishDay);
$('finish-day-central').addEventListener('click', openFinishDay);
$('confirm-finish-day').addEventListener('click', async () => {
    if (await lifecycle(`/days/${selectedDayId}/finish`)) {$('finish-day-dialog').close(); openTab('days'); notice('Dia de racha encerrado! Confira o resumo final abaixo.');}
});
const todayInput = new Date();
$('day-date').value = `${todayInput.getFullYear()}-${String(todayInput.getMonth()+1).padStart(2,'0')}-${String(todayInput.getDate()).padStart(2,'0')}`;
setInterval(async () => {
    if (!loaded || busy || polling || document.querySelector('dialog[open]')) return;
    polling = true;
    try {
        const [result, refreshedDays, notifications] = await Promise.all([requestJson('/racha'), requestJson('/days'), requestJson('/profile/notifications')]);
        profileNotifications = notifications;
        if (busy || document.querySelector('dialog[open]')) return;
        const previous = state.current;
        state = result.data; revision = result.revision; days = refreshedDays; conflicted = false;
        handleTransition(previous);
        render();
    } catch { /* Keep the last confirmed state until the connection returns. */ }
    finally {polling = false;}
}, 10000);
$('today').textContent = new Date().toLocaleDateString('pt-BR',{weekday:'long',day:'numeric',month:'long'});
setInterval(() => {
    renderClock();
    if (state.current && state.current.status !== 'penalties' && elapsed() >= state.current.duration * 60 && !busy && loaded && !conflicted && Date.now() >= retryAfter) {
        mutate(() => {});
    }
}, 500);
openTab('profile');
render();
async function load() {
    try {
        const response = await fetch('/racha', {headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('Não foi possível carregar o racha. Recarregue a página para tentar novamente.');
        const result = await response.json();
        state = result.data; revision = result.revision;
        await refreshDays();
        const profile = await requestJson('/profile');
        const remembered = activePlayers().find((player) => player.id === profilePlayerId);
        if (profile.player) profilePlayerId = profile.player.id;
        else if (remembered) await rememberProfile(remembered.id);
        else profilePlayerId = null;
        await refreshNotifications();
        loaded = true;
        if (new URLSearchParams(location.search).has('day')) openTab('days');
        $('save-status').textContent = '✓ Tudo salvo';
    } catch (error) {notice(error.message); $('save-status').textContent = 'Falha ao carregar';}
    finally {busy = false; render(); if (state.current?.status === 'penalties') handleTransition(null);}
}
load();


function generalLink() {
    const url = new URL(location.href); url.search = ''; url.hash = ''; return url.href;
}
async function copyGeneralLink(field) {
    $(field).hidden = false; $(field).value = generalLink();
    try {await navigator.clipboard.writeText(generalLink()); notice('Link único do racha copiado!');}
    catch {$(field).focus(); $(field).select(); notice('Selecione e copie o link exibido. É o mesmo para todos.');}
}
async function rememberProfile(player) {
    const result = await requestJson('/profile', {method:'PUT',body:JSON.stringify({player})});
    profilePlayerId = result.player.id;
    await refreshNotifications();
    try {localStorage.setItem('frangolinos-player',profilePlayerId);} catch {}
}
async function addPlayer(name, position, ownProfile = false) {
    if (busy || !loaded || !name) return false;
    busy = true; render();
    try {
        const result = await requestJson('/players', {method:'POST',body:JSON.stringify({name,position})});
        state = result.data; revision = result.revision; conflicted = false;
        if (ownProfile) await rememberProfile(result.player.id);
        notice(ownProfile ? 'Seu perfil está pronto! Confirme sua presença abaixo.' : 'Jogador adicionado!');
        return true;
    } catch (error) {notice(error.message); return false;}
    finally {busy = false; render();}
}
$('profile-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const player = $('profile-player').value;
    if (busy || !loaded || !player) return;
    busy = true; render();
    try {await rememberProfile(player); notice('Perfil selecionado! Este celular vai lembrar seu nome.');}
    catch (error) {notice(error.message);}
    finally {busy = false; render();}
});
$('profile-registration-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    if (await addPlayer($('profile-name').value.trim(), $('profile-position').value, true)) $('profile-name').value = '';
});
async function setMyAttendance(dayId, present) {
    if (busy || !loaded || !profilePlayerId) return;
    busy = true; render();
    try {
        const result = await requestJson(`/profile/days/${dayId}/attendance`, {method:'PUT',body:JSON.stringify({present})});
        days = days.map((day) => day.id === result.id ? result : day);
        notice(present ? 'Sua presença está confirmada!' : 'Você marcou que não vai jogar.');
    } catch (error) {notice(error.message);}
    finally {busy = false; render();}
}
async function setMyPayment(dayId) {
    if (busy || !loaded || !profilePlayerId) return;
    busy = true; render();
    try {
        const result = await requestJson(`/profile/days/${dayId}/payments`, {method:'PUT',body:JSON.stringify({paid:true})});
        days = days.map((day) => day.id === result.id ? result : day);
        notice('Seu pagamento foi registrado para este racha!');
    } catch (error) {notice(error.message);}
    finally {busy = false; render();}
}
function renderProfile() {
    const player = activePlayers().find((item) => item.id === profilePlayerId);
    $('profile-title').textContent = player ? `Olá, ${player.name}!` : 'Quem é você?';
    $('profile-player').innerHTML = '<option value="">Escolha seu nome</option>' + activePlayers().map((item) => `<option value="${item.id}" ${item.id === player?.id ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('');
    for (const id of ['choose-profile','register-profile']) $(id).disabled = busy || !loaded;
    $('profile-content').hidden = !player;
    if (!player) return;
    renderNotifications();
    const matches = state.matches.filter((match) => Object.values(match.participants ?? match.teams).flat().includes(player.id));
    const goals = matches.reduce((total,match) => total + match.goals.filter((goal) => goal.player === player.id).length,0);
    const wins = matches.filter((match) => (match.participants ?? match.teams)[match.winner]?.includes(player.id)).length;
    const assists = matches.reduce((total,match) => total + match.goals.filter((goal) => goal.assist === player.id).length,0);
    $('profile-stats').innerHTML = [['Partidas',matches.length],['Gols',goals],['Assistências',assists],['Vitórias',wins]].map(([label,value]) => `<article class="stat"><span>${label}</span><strong>${value}</strong><small>Minhas estatísticas gerais</small></article>`).join('');
    const upcoming = days.filter((day) => !day.finished_at && !day.declined_players?.includes(player.id));
    $('profile-attendance-panel').hidden = upcoming.length === 0;
    $('profile-days').innerHTML = upcoming.map((day) => {
        const present = day.attendees.includes(player.id), departed = day.departed?.includes(player.id), paid = day.paid_players?.includes(player.id);
        const playing = state.current?.dayId === day.id && isPlaying(player.id);
        return `<div class="profile-day"><div><strong>${escapeHtml(dayLabel(day))}</strong><p>${escapeHtml(day.location)}</p><small>${departed ? 'Você saiu deste racha' : present ? '✓ Presença confirmada' : 'Presença ainda não confirmada'}${player.position === 'goalkeeper' ? ' · Goleiro isento de pagamento' : present || paid ? ` · ${paid ? '✓ Pago' : 'Pagamento pendente'}` : ''}</small></div><div class="attendance-actions"><button class="${present ? 'attendance-present' : 'primary'}" data-my-attendance="${day.id}" data-present="true" ${busy || departed || present ? 'disabled' : ''}>${present ? '✓ Vou jogar' : 'Vou jogar'}</button><button class="secondary attendance-decline" data-my-attendance="${day.id}" data-present="false" ${busy || playing || departed ? 'disabled' : ''}>Não vou</button>${player.position === 'goalkeeper' ? '<span class="payment-not-applicable">Isento</span>' : present || paid ? `<button class="payment-button profile-payment ${paid ? 'payment-paid' : 'payment-pending'}" data-my-payment="${day.id}" ${busy || paid ? 'disabled' : ''} aria-label="${paid ? 'Pagamento confirmado' : 'Confirmar meu pagamento'}">${paid ? '✓ Pago' : 'Confirmar pagamento'}</button>` : ''}</div></div>`;
    }).join('') || empty('Nenhum racha aberto no momento.');
    $('profile-matches').innerHTML = [...matches].reverse().slice(0,5).map((match) => `<div class="profile-day"><span>${date(match.date)} · Azul ${score(match,'a')} × ${score(match,'b')} Vermelho</span><strong>${match.goals.filter((goal) => goal.player === player.id).length} gols</strong></div>`).join('') || empty('Suas partidas vão aparecer aqui assim que você jogar.');
}


function applyTheme(theme) {
    const dark = theme === 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    $('theme-toggle').setAttribute('aria-pressed', String(dark));
    $('theme-toggle').setAttribute('aria-label', dark ? 'Ativar modo claro' : 'Ativar modo escuro');
    $('theme-label').textContent = dark ? 'Modo claro' : 'Modo escuro';
    $('theme-icon').textContent = dark ? '☀' : '☾';
}
function storedTheme() {
    try {const theme = localStorage.getItem('frangolinos-theme'); return theme === 'dark' || theme === 'light' ? theme : null;} catch {return null;}
}
const systemTheme = typeof matchMedia === 'function' ? matchMedia('(prefers-color-scheme: dark)') : null;
applyTheme(storedTheme() ?? (systemTheme?.matches ? 'dark' : 'light'));
$('theme-toggle').addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    applyTheme(theme);
    try {localStorage.setItem('frangolinos-theme', theme);} catch {}
});
systemTheme?.addEventListener('change', (event) => {
    if (!storedTheme()) applyTheme(event.matches ? 'dark' : 'light');
});

function resetDayForm() {
    editingDayId = null;
    $('save-day').textContent = '＋ Marcar racha';
    $('cancel-edit-day').hidden = true;
}
$('edit-day').addEventListener('click', () => {
    const day = selectedDay();
    if (busy || !day || day.finished_at) return;
    editingDayId = day.id;
    $('day-date').value = day.date;
    $('day-time').value = day.time;
    $('day-end-time').value = day.end_time ?? '';
    $('day-location').value = day.location;
    $('save-day').textContent = 'Salvar alteração';
    $('cancel-edit-day').hidden = false;
    $('day-form').scrollIntoView({behavior:'smooth',block:'center'});
});
$('cancel-edit-day').addEventListener('click', resetDayForm);
async function refreshNotifications() {
    profileNotifications = await requestJson('/profile/notifications');
}
function renderNotifications() {
    $('profile-notifications').innerHTML = profileNotifications.map((item) => `<div class="profile-day"><div><strong>${item.read_at ? '' : '● '}${escapeHtml(item.data.title)}</strong><p>${escapeHtml(dayLabel(item.data))} · ${escapeHtml(item.data.location)}</p></div><button class="secondary" data-open-notification="${item.id}" ${busy ? 'disabled' : ''}>Ver racha</button></div>`).join('') || empty('Você receberá avisos aqui quando um racha for marcado ou alterado.');
}
$('profile-notifications').addEventListener('click', async (event) => {
    const button = event.target.closest('[data-open-notification]');
    if (!button || busy) return;
    const item = profileNotifications.find((entry) => entry.id === button.dataset.openNotification);
    if (!item) return;
    busy = true;
    try {
        await requestJson(`/profile/notifications/${item.id}/read`, {method:'PUT'});
        await refreshNotifications();
        selectedDayId = item.data.day_id;
        openTab('days');
    } catch (error) {notice(error.message);}
    finally {busy = false; render();}
});
