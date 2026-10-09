import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {webcrypto} from 'node:crypto';
import {cloneState, drawTeams, buildLineup, remainingSeconds, dailyStatistics} from '../../resources/js/match-tools.js';

const players = Array.from({length:15}, (_, index) => ({id:`00000000-0000-4000-8000-${String(index+1).padStart(12,'0')}`,name:`Jogador ${index+1}`,position:index < 3 ? 'goalkeeper':'outfield'}));

test('draw creates exactly two complete teams and leaves the remaining players on the bench', () => {
    const assignments = drawTeams(players, () => 0.5);
    const lineup = buildLineup(players, assignments);
    assert.deepEqual([...new Set(Object.values(assignments))].sort(), ['a','b','bank']);
    assert.equal(lineup.teams.a.length, 6);
    assert.equal(lineup.teams.b.length, 6);
    assert.equal(lineup.bench.length, 3);
    for (const team of ['a','b']) {assert.ok(lineup.teams[team].includes(lineup.goalkeepers[team])); assert.equal(players.find(player=>player.id===lineup.goalkeepers[team]).position,'goalkeeper');}
});

test('draw allows flexible positions but requires twelve players', () => {
    assert.throws(() => drawTeams(players.slice(0,11)), /12 jogadores/);
    for (const position of ['outfield','goalkeeper']) {
        const roster = players.map(player=>({...player,position}));
        const lineup = buildLineup(roster,drawTeams(roster,()=>0.5));
        for (const team of ['a','b']) assert.ok(lineup.teams[team].includes(lineup.goalkeepers[team]));
        assert.equal(lineup.bench.length,3);
    }
});

test('manual roster changes cannot create an incomplete team', () => {
    const assignments = drawTeams(players, () => 0.5);
    const player = Object.keys(assignments).find((id) => assignments[id] === 'a');
    assignments[player] = 'bank';
    assert.throws(() => buildLineup(players, assignments), /5 jogadores de linha/);
});

async function harness({rejectSave = false, rejectPlayer = false, responses = {}, storage = {}, systemDark = false} = {}) {
    const elements = new Map();
    class Element {
        constructor(id) {this.attributes={}; this.id=id; this.value=''; this.hidden=false; this.open=false; this.dataset={}; this.handlers={}; this.classList={toggle(){}};}
        setAttribute(name,value) {this.attributes[name]=value;}
        getAttribute(name) {return this.attributes[name];}
        addEventListener(type, callback) {this.handlers[type]=callback;}
        showModal() {this.open=true;}
        close() {this.open=false;}
        focus() {this.focused=true;}
        scrollIntoView(options) {this.lastScroll=options;}
        select() {}
        closest() {return null;}
    }
    const element = (id) => {if (!elements.has(id)) elements.set(id,new Element(id)); return elements.get(id);};
    const day = {id:'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3',date:'2026-10-10',time:'19:00',end_time:'21:00',location:'Quadra',attendees:players.map((player) => player.id)};
    let data = {players:cloneState(players), matches:[], current:null};
    const requests = [];
    const handlers = {};
    const timers = new Map(); let timerId = 0;
    const themeMedia = {matches:systemDark,addEventListener(type,handler){this.handler=handler;}};
    const document = {
        documentElement:element('html'),
        getElementById:element,
        querySelector: (selector) => selector.startsWith('meta') ? {content:'test-token'} : [...elements.values()].find((item) => item.id.endsWith('-dialog') && item.open) ?? null,
        querySelectorAll: (selector) => selector === 'dialog[open]' ? [...elements.values()].filter((item) => item.id.endsWith('-dialog') && item.open) : [],
        addEventListener(type, callback) {handlers[type]=callback;},
    };
    const context = vm.createContext({document, matchMedia:()=>themeMedia, localStorage:{getItem:(key)=>storage[key] ?? null,setItem:(key,value)=>{storage[key]=value;}}, location:{search:`?day=${day.id}`,href:`http://10.0.8.203:8001/?day=${day.id}`}, navigator:{}, URL, URLSearchParams, Date, Math, Uint8Array, crypto:{getRandomValues:webcrypto.getRandomValues.bind(webcrypto)}, cloneState, drawTeams, buildLineup, remainingSeconds, dailyStatistics, setInterval(){}, setTimeout(callback,delay){const id=++timerId;timers.set(id,{callback,delay});return id;},clearTimeout(id){timers.delete(id);}, fetch:async (url, options = {}) => {
        requests.push({url,options});
        if (responses[url]) return {ok:true,json:async()=>cloneState(typeof responses[url] === 'function' ? responses[url](options.body ? JSON.parse(options.body) : null,data) : responses[url])};
        if (url === '/days') return {ok:true,json:async()=>[day]};
        if (url === '/players' && rejectPlayer) return {ok:false,status:422,json:async()=>({errors:{name:['Este nome já está cadastrado.']}})};
        if (url === '/profile/notifications') return {ok:true,json:async()=>[]};
        if (options.method === 'PUT') {
            if (rejectSave) return {ok:false,status:422,json:async()=>({errors:{data:['O jogador retirou sua presença.']}})};
            data = JSON.parse(options.body).data;
            return {ok:true,json:async()=>({revision:1,data:cloneState(data)})};
        }
        return {ok:true,json:async()=>({revision:0,data:cloneState(data)})};
    }});
    const source = (await readFile(new URL('../../resources/js/app.js', import.meta.url),'utf8')).replace(/^import .*;\n/, '');
    vm.runInContext(source, context);
    await new Promise((resolve) => setImmediate(resolve));
    return {element, requests, context, timers, expireNotices(){for(const [id,timer] of [...timers]){timers.delete(id);timer.callback();}}, async trigger(dataset) {await handlers.click({target:{closest:()=>({dataset})}});}, async click(id) {await element(id).handlers.click();}, async submit(id) {await element(id).handlers.submit({preventDefault(){}});}};
}

test('draw then create submits a real match and closes the modal on local HTTP without randomUUID or structuredClone', async () => {
    const app = await harness();
    await app.click('new-match');
    assert.equal(app.element('setup-dialog').open, true);
    assert.match(app.element('setup-players').innerHTML, /Time Azul/);
    assert.match(app.element('setup-players').innerHTML, /Time Vermelho/);
    assert.match(app.element('setup-players').innerHTML, /Banco/);
    assert.doesNotMatch(app.element('setup-players').innerHTML, /data-player=/);
    assert.equal((app.element('setup-players').innerHTML.match(/data-setup-keeper=/g) ?? []).length,2);
    await app.click('balance-teams');
    await app.submit('setup-form');
    const write = app.requests.find((request) => request.options.method === 'PUT');
    assert.ok(write, 'create must send a request');
    const match = JSON.parse(write.options.body).data.current;
    assert.equal(match.duration,10);
    assert.equal(match.teams.a.length,6);
    assert.equal(match.teams.b.length,6);
    assert.equal(match.bench.length,3);
    assert.equal(app.element('setup-dialog').open,false);
    assert.equal(app.element('toggle-clock').disabled,false);
    assert.match(app.element('notice-message').textContent,/Partida criada/);
});

test('a rejected create leaves the modal open displays the server error and unlocks the create button', async () => {
    const app = await harness({rejectSave:true});
    await app.click('new-match');
    await app.submit('setup-form');
    assert.equal(app.element('setup-dialog').open,true);
    assert.equal(app.element('create-match').disabled,false);
    assert.match(app.element('notice-message').textContent,/retirou sua presença/);
    assert.equal(vm.runInContext('state.current',app.context),null);
});

test('the countdown starts at ten minutes decreases with elapsed time and never goes negative', () => {
    assert.equal(remainingSeconds(null),600);
    const match = {duration:10,elapsed:0,startedAt:1000000};
    assert.equal(remainingSeconds(match,1000000),600);
    assert.equal(remainingSeconds(match,1001000),599);
    assert.equal(remainingSeconds(match,1100000),500);
    assert.equal(remainingSeconds(match,2000000),0);
    assert.equal(remainingSeconds({...match,elapsed:100,startedAt:null},2000000),500);
});

test('daily statistics count both substituted players preserve goals and use the penalty winner without adding goals', () => {
    const day = {id:'day',attendees:['one','two','three'],departed:['one']};
    const state = {players:[{id:'one',name:'Um'},{id:'two',name:'Dois'},{id:'three',name:'Três'}],matches:[{dayId:'day',winner:'a',reason:'penalties',teams:{a:['two'],b:['three']},participants:{a:['one','two'],b:['three']},goals:[{player:'one',team:'a'},{player:'three',team:'b'}]},{dayId:'another-day',teams:{a:['one'],b:['three']},goals:[{player:'one',team:'a'}]}]};
    const stats = dailyStatistics(state,day);
    assert.equal(stats.matches,1);
    assert.equal(stats.goals,2);
    assert.deepEqual(stats.players.find((player)=>player.id==='one'),{id:'one',name:'Um',games:1,wins:1,goals:1,assists:0,departed:true});
    assert.equal(stats.players.find((player)=>player.id==='two').games,1);
    assert.equal(stats.scorers.length,2);
});

test('the match screen displays a decreasing clock after a match is created', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    assert.equal(app.element('clock').textContent,'10:00');
    vm.runInContext('state.current.elapsed = 123; renderClock();',app.context);
    assert.equal(app.element('clock').textContent,'07:57');
});


test('the penalty selector sends the selected winner preserves the tied score and releases the next match area', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const lineup = buildLineup(players,drawTeams(players,()=>0.5));
    const match = {id:'10000000-0000-4000-8000-000000000000',date:'2026-10-10T19:00:00Z',duration:10,dayId,elapsed:600,startedAt:null,...lineup,goals:[],status:'penalties'};
    const finished = {...match,status:'finished',winner:'b',reason:'penalties'};
    const app = await harness({responses:{'/racha/penalties':{revision:1,data:{players,matches:[finished],current:null,next:null}}}});
    vm.runInContext(`state.current = ${JSON.stringify(match)}; render(); handleTransition(null);`,app.context);
    assert.equal(app.element('penalties-dialog').open,true);
    assert.equal(app.element('toggle-clock').disabled,true);
    await app.trigger({penaltyWinner:'b'});
    const write = app.requests.find((request)=>request.url==='/racha/penalties');
    assert.equal(JSON.parse(write.options.body).winner,'b');
    assert.equal(app.element('penalties-dialog').open,false);
    assert.equal(app.element('next-match-card').hidden,false);
    assert.equal(app.element('next-match-card').focused,true);
    assert.equal(app.element('next-match-card').lastScroll.block,'start');
    assert.match(app.element('last-result-title').textContent,/Vermelho venceu nos pênaltis/);
    assert.equal(vm.runInContext('state.matches[0].goals.length',app.context),0);
});

test('the substitution form can remove a player only for the current day and sends their replacement', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const app = await harness({responses:{[`/days/${dayId}/availability`]:(input,data)=>({revision:2,data})}});
    await app.click('new-match'); await app.submit('setup-form');
    const match = JSON.parse(vm.runInContext('JSON.stringify(state.current)',app.context));
    const out = match.teams.a.find((id)=>players.find((player)=>player.id===id).position==='outfield');
    const replacement = match.bench.find((id)=>players.find((player)=>player.id===id).position==='outfield');
    await app.trigger({managePlayer:out});
    app.element('incoming-player').value = replacement;
    await app.submit('substitution-form');
    const write = app.requests.find((request)=>request.url===`/days/${dayId}/availability`);
    assert.deepEqual(JSON.parse(write.options.body),{revision:1,player:out,available:false,replacement});
    assert.equal(app.element('substitution-dialog').open,false);
});


test('payment toggles are saved separately from attendance and match state', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const app = await harness({responses:{[`/days/${dayId}/payments`]:(input)=>({id:dayId,date:'2026-10-10',time:'19:00',location:'Quadra',attendees:players.map(player=>player.id),paid_players:input.paid ? [input.player] : []})}});
    await app.trigger({paymentPlayer:players[3].id,paid:'true'});
    assert.match(app.element('attendance-list').innerHTML,/✓ Pago/);
    await app.trigger({paymentPlayer:players[3].id,paid:'false'});
    const writes = app.requests.filter(request=>request.options.method==='PUT');
    assert.equal(writes.length,2);
    assert.deepEqual(JSON.parse(writes[0].options.body),{player:players[3].id,paid:true});
    assert.deepEqual(JSON.parse(writes[1].options.body),{player:players[3].id,paid:false});
    assert.equal(vm.runInContext('state.current',app.context),null);
});

test('day leaderboard shows only five scorers while expandable match details retain every participant', async () => {
    const app = await harness();
    const lineup = buildLineup(players,drawTeams(players,()=>0.5));
    const matches = players.slice(0,7).map((player,index)=>({id:`match-${index}`,dayId:'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3',date:'2026-10-10',duration:10,elapsed:60,...lineup,goals:[{player:player.id,team:lineup.teams.a.includes(player.id)?'a':'b',time:40}],winner:'a',substitutions:[]}));
    vm.runInContext(`state.matches = ${JSON.stringify(matches)}; render();`,app.context);
    assert.equal(vm.runInContext('dailyStatistics(state, selectedDay()).players.length',app.context),15);
    const markup = app.element('day-statistics').innerHTML;
    assert.match(markup,/Top 5/);
    assert.equal((markup.match(/class="day-player-row"/g) ?? []).length,5);
    assert.equal((markup.match(/class="match-player-stat"/g) ?? []).length,7*12);
    assert.match(markup,/<details[^>]+data-day-match/);
    assert.match(markup,/Time Azul/);
    assert.match(markup,/Time Vermelho/);
});


test('the shared link selects a profile remembers it and confirms only its own attendance', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const storage = {};
    const app = await harness({storage,responses:{'/profile':(input)=>({player:input ? players.find(player=>player.id===input.player) : null}),[`/profile/days/${dayId}/attendance`]:(input)=>({id:dayId,date:'2026-10-10',time:'19:00',location:'Quadra',attendees:input.present ? [players[0].id] : []})}});
    app.element('profile-player').value = players[0].id;
    await app.submit('profile-form');
    assert.equal(storage['frangolinos-player'],players[0].id);
    assert.equal(app.element('profile-content').hidden,false);
    assert.match(app.element('profile-title').textContent,/Jogador 1/);
    await app.trigger({myAttendance:dayId,present:'true'});
    const write = app.requests.find(request=>request.url===`/profile/days/${dayId}/attendance`);
    assert.deepEqual(JSON.parse(write.options.body),{present:true});
    assert.match(app.element('profile-days').innerHTML,/Presença confirmada/);
    await app.click('share-profile');
    assert.equal(app.element('profile-share-url').value,'http://10.0.8.203:8001/');
});

test('a remembered name restores the profile after the server session expires', async () => {
    const app = await harness({storage:{'frangolinos-player':players[0].id},responses:{'/profile':input=>({player:input ? players[0] : null})}});
    const restore = app.requests.find(request=>request.url==='/profile' && request.options.method==='PUT');
    assert.deepEqual(JSON.parse(restore.options.body),{player:players[0].id});
    assert.equal(app.element('profile-content').hidden,false);
});

test('adding a name from the profile saves a player before selecting it', async () => {
    const newPlayer = {id:'10000000-0000-4000-8000-000000000050',name:'Novo amigo',position:'outfield',active:true};
    const app = await harness({responses:{'/players':(input,data)=>({player:newPlayer,data:{...data,players:[...data.players,newPlayer]},revision:1}),'/profile':input=>({player:input ? newPlayer : null})}});
    app.element('profile-name').value = 'Novo amigo';
    app.element('profile-position').value = 'outfield';
    await app.submit('profile-registration-form');
    const write = app.requests.find(request=>request.url==='/players');
    assert.deepEqual(JSON.parse(write.options.body),{name:'Novo amigo',position:'outfield'});
    assert.equal(app.element('profile-name').value,'');
    assert.match(app.element('profile-title').textContent,/Novo amigo/);
    assert.equal(app.element('register-profile').disabled,false);
});

test('a failed player registration displays the reason and unlocks the form', async () => {
    const app = await harness({rejectPlayer:true});
    app.element('profile-name').value = 'Jogador 1';
    app.element('profile-position').value = 'outfield';
    await app.submit('profile-registration-form');
    assert.match(app.element('notice-message').textContent,/já está cadastrado/);
    assert.equal(app.element('register-profile').disabled,false);
    assert.equal(app.element('profile-name').value,'Jogador 1');
});


test('attendance has only presence and payment actions while substitutions and returns stay in the match', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    const bench = vm.runInContext('state.current.bench[0]',app.context);
    vm.runInContext(`days[0].departed = [${JSON.stringify(bench)}]; render();`,app.context);
    const attendance = app.element('attendance-list').innerHTML;
    assert.match(attendance,/attendance-table-heading/);
    assert.match(attendance,/data-attendance-player/);
    assert.match(attendance,/data-payment-player/);
    assert.doesNotMatch(attendance,/data-manage-player|data-return-player|substituir/i);
    assert.match(app.element('lineup').innerHTML,/data-manage-player/);
    assert.match(app.element('departed-lineup').innerHTML,/data-return-player/);
    assert.match(app.element('departed-lineup').innerHTML,/Retornar ao banco/);
    vm.runInContext('state.current = null; render();',app.context);
    assert.equal(app.element('departed-lineup').innerHTML,'');
});


test('dark mode follows the device by default and the switch persists a manual choice', async () => {
    const storage = {};
    const app = await harness({systemDark:true,storage});
    assert.equal(app.element('html').dataset.theme,'dark');
    assert.equal(app.element('theme-toggle').getAttribute('aria-pressed'),'true');
    assert.equal(app.element('theme-label').textContent,'Modo claro');
    await app.click('theme-toggle');
    assert.equal(app.element('html').dataset.theme,'light');
    assert.equal(storage['frangolinos-theme'],'light');
    assert.equal(app.element('theme-toggle').getAttribute('aria-label'),'Ativar modo escuro');
    const reloaded = await harness({systemDark:true,storage});
    assert.equal(reloaded.element('html').dataset.theme,'light');
});

test('an explicit dark preference overrides the device light theme', async () => {
    const app = await harness({storage:{'frangolinos-theme':'dark'},systemDark:false});
    assert.equal(app.element('html').dataset.theme,'dark');
    await app.click('theme-toggle');
    assert.equal(app.element('theme-toggle').getAttribute('aria-pressed'),'false');
});


test('the profile payment button records only the selected player payment and updates the summary', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const app = await harness({responses:{'/profile':()=>({player:players[3]}),[`/profile/days/${dayId}/payments`]:()=>({id:dayId,date:'2026-10-10',time:'19:00',location:'Quadra',attendees:players.map(player=>player.id),paid_players:[players[3].id]})}});
    assert.match(app.element('profile-days').innerHTML,/Confirmar pagamento/);
    await app.trigger({myPayment:dayId});
    const write = app.requests.find(request=>request.url===`/profile/days/${dayId}/payments`);
    assert.deepEqual(JSON.parse(write.options.body),{paid:true});
    assert.match(app.element('profile-days').innerHTML,/Pagamento confirmado/);
    assert.match(app.element('profile-days').innerHTML,/✓ Pago/);
    assert.match(app.element('attendance-list').innerHTML,/✓ Pago/);
    assert.match(app.element('notice-message').textContent,/pagamento foi registrado/);
});


test('the goal picker selects scorer and optional teammate assist using buttons', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    vm.runInContext('state.current.elapsed = 30; render();',app.context);
    const ids = JSON.parse(vm.runInContext('JSON.stringify(state.current.teams.a)',app.context));
    await app.click('add-goal');
    assert.equal(app.element('confirm-goal').disabled,true);
    await app.trigger({goalTeam:'a'});
    await app.trigger({goalScorer:ids[0]});
    assert.equal(app.element('goal-assist-section').hidden,false);
    assert.doesNotMatch(app.element('goal-assist-options').innerHTML,new RegExp(`data-goal-assist="${ids[0]}"`));
    await app.trigger({goalAssist:ids[1]});
    await app.submit('goal-form');
    const writes = app.requests.filter(request=>request.url==='/racha' && request.options.method==='PUT');
    const goal = JSON.parse(writes.at(-1).options.body).data.current.goals[0];
    assert.equal(goal.player,ids[0]); assert.equal(goal.assist,ids[1]); assert.equal(goal.team,'a');
    assert.equal(app.element('goal-dialog').open,false);
    assert.match(app.element('events').innerHTML,/Passe de/);
});

test('switching goal teams clears the scorer and assist and a goal without assist remains valid', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    vm.runInContext('state.current.elapsed = 30; render();',app.context);
    const match = JSON.parse(vm.runInContext('JSON.stringify(state.current)',app.context));
    await app.click('add-goal'); await app.trigger({goalTeam:'a'});
    await app.trigger({goalScorer:match.teams.a[0]}); await app.trigger({goalAssist:match.teams.a[1]});
    await app.trigger({goalTeam:'b'});
    assert.equal(app.element('goal-player').value,''); assert.equal(app.element('goal-assist').value,'');
    assert.equal(app.element('confirm-goal').disabled,true);
    await app.trigger({goalScorer:match.teams.b[0]}); await app.submit('goal-form');
    assert.equal(vm.runInContext('state.current.goals[0].assist',app.context),null);
});


test('floating notifications disappear automatically can be dismissed and do not follow navigation', async () => {
    const app = await harness();
    vm.runInContext("notice('Pagamento confirmado!');",app.context);
    assert.equal(app.element('notice').hidden,false);
    assert.equal(app.timers.size,1);
    app.expireNotices();
    assert.equal(app.element('notice').hidden,true);
    vm.runInContext("notice('Aviso anterior'); notice('Aviso novo');",app.context);
    assert.equal(app.timers.size,1);
    assert.equal(app.element('notice-message').textContent,'Aviso novo');
    await app.click('dismiss-notice');
    assert.equal(app.element('notice').hidden,true);
    assert.equal(app.timers.size,0);
    vm.runInContext("notice('Partida finalizada');",app.context);
    await app.trigger({tab:'players'});
    assert.equal(app.element('notice').hidden,true);
});


test('the central schedules a racha when today has no open day including a closed or future day', async () => {
    const app = await harness();
    vm.runInContext("days[0].finished_at = '2026-10-07T20:00:00Z'; openTab('match');",app.context);
    assert.match(app.element('new-match').textContent,/Marcar racha/);
    await app.click('new-match');
    assert.equal(app.element('page-title').textContent,'Dias de racha');
    assert.equal(app.element('day-date').focused,true);
    assert.equal(app.element('setup-dialog').open,false);
    vm.runInContext("days[0].finished_at = null; days[0].date = '2099-01-01'; openTab('match');",app.context);
    assert.match(app.element('new-match').textContent,/Marcar racha/);
    vm.runInContext("days[0].date = todayKey(); openTab('match');",app.context);
    assert.match(app.element('new-match').textContent,/Nova partida/);
    vm.runInContext("days = []; render();",app.context);
    assert.match(app.element('new-match').textContent,/Marcar racha/);
});


test('the central can open day closure and blocks closing while a match is active', async () => {
    const app = await harness();
    assert.equal(app.element('finish-day-central').hidden,false);
    assert.equal(app.element('finish-day-central').disabled,false);
    await app.click('finish-day-central');
    assert.equal(app.element('finish-day-dialog').open,true);
    app.element('finish-day-dialog').close();
    await app.click('new-match'); await app.submit('setup-form');
    vm.runInContext('state.current.elapsed = 1; render();',app.context);
    assert.equal(app.element('finish-day-central').disabled,true);
    await app.click('finish-day-central');
    assert.equal(app.element('finish-day-dialog').open,false);
    vm.runInContext("state.current = null; days[0].finished_at = '2026-10-07T21:00:00Z'; render();",app.context);
    assert.equal(app.element('finish-day-central').hidden,true);
});


test('payment actions appear only after attendance is confirmed while recorded payments remain visible', async () => {
    const app = await harness({responses:{'/profile':()=>({player:players[3]})}});
    vm.runInContext('days[0].attendees = []; render();',app.context);
    assert.doesNotMatch(app.element('profile-days').innerHTML,/data-my-payment/);
    assert.doesNotMatch(app.element('attendance-list').innerHTML,/data-payment-player/);
    assert.doesNotMatch(app.element('profile-days').innerHTML,/Pagamento pendente/);
    vm.runInContext(`days[0].attendees = [${JSON.stringify(players[3].id)}]; render();`,app.context);
    assert.match(app.element('profile-days').innerHTML,/Confirmar pagamento/);
    assert.match(app.element('attendance-list').innerHTML,/Marcar pago/);
    vm.runInContext(`days[0].attendees = []; days[0].paid_players = [${JSON.stringify(players[3].id)}]; render();`,app.context);
    assert.match(app.element('profile-days').innerHTML,/✓ Pago/);
});


test('the day lists only registered players and excludes goalkeepers from pending payments', async () => {
    const app = await harness({responses:{'/profile':()=>({player:players[0]})}});
    vm.runInContext(`days[0].attendees = [${JSON.stringify(players[0].id)}, ${JSON.stringify(players[3].id)}]; render();`,app.context);
    assert.match(app.element('attendance-list').innerHTML,/Jogador 1</);
    assert.match(app.element('attendance-list').innerHTML,/Jogador 4</);
    assert.doesNotMatch(app.element('attendance-list').innerHTML,/Jogador 2</);
    assert.equal((app.element('attendance-list').innerHTML.match(/data-payment-player/g) ?? []).length,1);
    assert.match(app.element('attendance-list').innerHTML,/Isento/);
    assert.match(app.element('payment-summary').innerHTML,/<b>1<\/b> pendentes/);
    assert.doesNotMatch(app.element('profile-days').innerHTML,/data-my-payment/);
    assert.match(app.element('profile-days').innerHTML,/Goleiro isento/);
});

test('editing a schedule sends both times and retains the selected day', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const app = await harness({responses:{[`/days/${dayId}/schedule`]:(input)=>({id:dayId,...input,attendees:[]})}});
    await app.click('edit-day');
    assert.equal(app.element('day-time').value,'19:00');
    app.element('day-end-time').value = '22:00';
    app.element('day-time').value = '20:00';
    await app.submit('day-form');
    const write = app.requests.find(request=>request.url===`/days/${dayId}/schedule`);
    assert.equal(write.options.method,'PUT');
    assert.equal(JSON.parse(write.options.body).end_time,'22:00');
    assert.equal(JSON.parse(write.options.body).time,'20:00');
    assert.equal(app.element('cancel-edit-day').hidden,true);
});

test('profile notifications display the new schedule and opening one marks only that notice read', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    let read = false;
    const item = () => ({id:'notice-id',read_at:read ? '2026-10-07' : null,data:{day_id:dayId,title:'Novo dia de racha!',date:'2026-10-10',time:'19:00',end_time:'21:00',location:'Quadra'}});
    const app = await harness({responses:{'/profile':()=>({player:players[0]}),'/profile/notifications':()=>[item()],'/profile/notifications/notice-id/read':()=>{read=true;return item();}}});
    assert.match(app.element('profile-notifications').innerHTML,/● Novo dia de racha!/);
    assert.match(app.element('profile-notifications').innerHTML,/19:00 às 21:00/);
    assert.match(app.element('profile-notifications').innerHTML,/3 vagas de linha · 1 vaga de goleiro/);
    assert.match(app.element('profile-notifications').innerHTML,/Cancelar/);
    assert.equal(app.element('profile-days').innerHTML, '');
    await app.element('profile-notifications').handlers.click({target:{closest:()=>({dataset:{openNotification:'notice-id'}})}});
    assert.equal(read,true);
    assert.doesNotMatch(app.element('profile-notifications').innerHTML,/● /);
    assert.equal(app.element('page-title').textContent,'Dias de racha');
});


test('canceling keeps the racha available to participate again', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const app = await harness({responses:{'/profile':()=>({player:players[3]}),[`/profile/days/${dayId}/attendance`]:()=>({id:dayId,date:'2026-10-10',time:'19:00',location:'Quadra',attendees:[],declined_players:[players[3].id]})}});
    assert.equal(app.element('profile-attendance-panel').hidden,false);
    await app.trigger({myAttendance:dayId,present:'false'});
    assert.equal(app.element('profile-attendance-panel').hidden,false);
    assert.match(app.element('profile-days').innerHTML,/Participar/);
    assert.doesNotMatch(app.element('profile-days').innerHTML,/Não vou/);
    vm.runInContext(`profilePlayerId = ${JSON.stringify(players[4].id)}; render();`,app.context);
    assert.equal(app.element('profile-attendance-panel').hidden,false);
    assert.match(app.element('profile-days').innerHTML,/data-my-attendance/);
});

test('central totals stay in the active day while general statistics include historical matches and tied leaders', async () => {
    const app = await harness();
    vm.runInContext(`
        const activeDayId = days[0].id;
        const oldMatch = {id:'old',dayId:'previous-day',teams:{a:[state.players[0].id],b:[state.players[1].id]},goals:[{team:'a',player:state.players[0].id,assist:state.players[1].id}],elapsed:600,duration:10,date:'2026-10-01'};
        state.matches = [oldMatch];
        state.current = {...oldMatch,id:'active',dayId:activeDayId,participants:{a:[state.players[0].id,state.players[2].id],b:[state.players[1].id]},goals:[{team:'b',player:state.players[1].id,assist:state.players[2].id}]};
        render();
    `, app.context);
    assert.equal(app.element('stat-matches').textContent, 0);
    assert.equal(app.element('stat-goals').textContent, 1);
    assert.equal(app.element('stat-leader').textContent, 'Jogador 2');
    assert.match(app.element('general-statistics').innerHTML, /Gols marcados<\/span><strong>2/);
    assert.match(app.element('general-statistics').innerHTML, /Jogador 1, Jogador 2/);
    assert.match(app.element('statistics-games').innerHTML, /Jogador 3<\/span><strong>1/);
    assert.match(app.element('statistics-assists').innerHTML, /Jogador 2/);
    await app.trigger({tab:'statistics'});
    assert.equal(app.element('page-title').textContent, 'Estatísticas');
    vm.runInContext('selectedDayId = null; state.current = null; render();', app.context);
    assert.equal(app.element('stat-goals').textContent, 0);
    assert.equal(app.element('stat-leader').textContent, '—');
});

test('day attendance offers the connected profile its own play action and keeps other registered players separate', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const app = await harness({responses:{'/profile':()=>({player:players[3]}),[`/profile/days/${dayId}/attendance`]:input=>({id:dayId,date:'2026-10-10',time:'19:00',location:'Quadra',attendees:input.present ? [players[3].id] : []})}});
    assert.equal(app.element('day-profile-attendance').hidden, false);
    assert.equal(app.element('play-day').textContent, '✓ Você vai jogar');
    assert.equal(app.element('play-day').disabled, true);
    assert.doesNotMatch(app.element('attendance-player').innerHTML, new RegExp(players[3].id));
    assert.doesNotMatch(app.element('attendance-player').innerHTML, new RegExp(players[4].id));
    vm.runInContext('days[0].attendees = days[0].attendees.filter(id => id !== state.players[4].id); render();', app.context);
    assert.match(app.element('attendance-player').innerHTML, new RegExp(players[4].id));
    await app.click('skip-day');
    assert.equal(app.element('play-day').textContent, 'Jogar');
    assert.equal(app.element('play-day').disabled, false);
    await app.click('play-day');
    const writes = app.requests.filter(request=>request.url===`/profile/days/${dayId}/attendance`);
    assert.deepEqual(writes.map(request=>JSON.parse(request.options.body)), [{present:false},{present:true}]);
    vm.runInContext('days[0].finished_at = "2026-10-10T21:00:00"; render();', app.context);
    assert.equal(app.element('day-profile-attendance').hidden, true);
    assert.equal(app.element('play-day').disabled, true);
});

test('day attendance without a connected profile allows selecting any registered player', async () => {
    const app = await harness();
    assert.equal(app.element('day-profile-attendance').hidden, true);
    assert.equal(app.element('attendance-player-label').textContent, 'Adicionar jogador cadastrado');
    assert.doesNotMatch(app.element('attendance-player').innerHTML, new RegExp(players[3].id));
    vm.runInContext('days[0].attendees = []; render();', app.context);
    assert.match(app.element('attendance-player').innerHTML, new RegExp(players[3].id));
});

test('temporary goalkeeper switches the team role without changing registered player positions', async () => {
    const app = await harness();
    await app.click('new-match');
    await app.submit('setup-form');
    const roles = vm.runInContext('({team:"a", old:state.current.goalkeepers.a, next:state.current.teams.a.find(id => id !== state.current.goalkeepers.a), positions:JSON.stringify(state.players)})', app.context);
    await app.trigger({temporaryKeeper:roles.next,keeperTeam:'a'});
    assert.equal(vm.runInContext('state.current.goalkeepers.a', app.context), roles.next);
    assert.equal(vm.runInContext('JSON.stringify(state.players)', app.context), roles.positions);
    assert.match(app.element('lineup').innerHTML, new RegExp(`data-temporary-keeper="${roles.old}"`));
    assert.match(app.element('lineup').innerHTML, /Na linha/);
    assert.equal(app.requests.filter(request=>request.options.method==='PUT').length, 2);
});

test('sharing a day includes the selected day and updates when another day is selected', async () => {
    const app = await harness();
    await app.click('share-day');
    assert.equal(app.element('share-url').value, 'http://10.0.8.203:8001/?day=ef83f52e-9c39-4936-bd4b-fb360ea0b4f3');
    vm.runInContext('days.push({...days[0],id:"another-day"});', app.context);
    await app.trigger({chooseDay:'another-day'});
    assert.equal(app.element('share-url').value, 'http://10.0.8.203:8001/?day=another-day');
    await app.click('share-profile');
    assert.equal(app.element('profile-share-url').value, 'http://10.0.8.203:8001/');
});


test('setup marks arrivals before drawing and excludes participants who have not arrived', async () => {
    const app = await harness();
    vm.runInContext('days[0].arrived_players = [];', app.context);
    await app.click('new-match');
    assert.equal(app.element('setup-dialog').open,true);
    assert.equal(app.element('create-match').disabled,true);
    assert.match(app.element('setup-arrivals').innerHTML,/Marcar chegada/);
    assert.equal(vm.runInContext('setupPlayers.length',app.context),0);
    vm.runInContext('days[0].arrived_players = state.players.slice(0,12).map(player=>player.id); setupPlayers = cloneState(confirmedPlayers()); setupAssignments = drawTeams(setupPlayers); renderSetup();',app.context);
    assert.equal(app.element('create-match').disabled,false);
    assert.equal(vm.runInContext('setupPlayers.length',app.context),12);
    assert.doesNotMatch(app.element('setup-players').innerHTML,/Jogador 13</);
});


test('draw prioritizes dedicated goalkeepers then dual position players before outfield players', () => {
    for (const dedicated of [0,1,2]) {
        const roster = players.map((player,index)=>({...player,position:index < dedicated ? 'goalkeeper' : index < dedicated + 2 ? 'both' : 'outfield'}));
        const lineup = buildLineup(roster,drawTeams(roster,()=>0.5));
        const keepers = Object.values(lineup.goalkeepers).map(id=>roster.find(player=>player.id===id));
        assert.equal(keepers.filter(player=>player.position==='goalkeeper').length,dedicated);
        assert.equal(keepers.filter(player=>player.position==='both').length,2-dedicated);
    }
});


test('the central shows the day session only for today and counts arrivals separately from registrations', async () => {
    const app = await harness();
    vm.runInContext('days[0].date = todayKey(); days[0].arrived_players = [state.players[0].id]; openTab("match");',app.context);
    assert.equal(app.element('day-session').hidden,false);
    assert.match(app.element('day-arrivals').innerHTML,/Marcar chegada/);
    assert.match(app.element('session-arrival-count').textContent,/1 presentes/);
    assert.equal(app.element('stat-players').textContent,1);
    vm.runInContext('days[0].date = "2099-01-01"; render();',app.context);
    assert.equal(app.element('day-session').hidden,true);
    assert.equal(app.element('day-arrivals').innerHTML,'');
    const view = await readFile(new URL('../../resources/views/welcome.blade.php',import.meta.url),'utf8');
    const dayTab = view.split('<section id="tab-days"')[1].split('<section id="tab-players"')[0];
    assert.doesNotMatch(dayTab,/id="day-arrivals"|id="draw-day"|id="finish-day/);
});


test('arrival cards show only pending participants and disappear after arrival is saved', async () => {
    const dayId = 'ef83f52e-9c39-4936-bd4b-fb360ea0b4f3';
    const day = {id:dayId,date:new Date().toLocaleDateString('en-CA',{timeZone:'America/Fortaleza'}),time:'19:00',location:'Quadra',attendees:players.map(player=>player.id),arrived_players:[]};
    const app = await harness({responses:{'/days':()=>[day],[`/days/${dayId}/arrival`]:(input,data)=>{day.arrived_players.push(input.player);return {data,revision:1};}}});
    await app.trigger({tab:'match'});
    assert.match(app.element('day-arrivals').innerHTML,/class="arrival-card"/);
    assert.match(app.element('day-arrivals').innerHTML,/Jogador 1</);
    await app.trigger({arrivalPlayer:players[0].id,arrived:'true'});
    assert.doesNotMatch(app.element('day-arrivals').innerHTML,/Jogador 1</);
    assert.match(app.element('day-arrivals').innerHTML,/Jogador 2</);
    assert.doesNotMatch(app.element('setup-arrivals').innerHTML,/Jogador 1</);
    const write = app.requests.find(request=>request.url===`/days/${dayId}/arrival`);
    assert.equal(JSON.parse(write.options.body).arrived,true);
    assert.match(app.element('session-arrival-count').textContent,/1 presentes/);
});


test('day closure is available for unstarted matches and sessions from previous days', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    assert.equal(app.element('finish-day-central').disabled,false);
    await app.click('finish-day-central');
    assert.equal(app.element('finish-day-dialog').open,true);
    app.element('finish-day-dialog').close();
    vm.runInContext('state.current = null; days[0].date = "2026-01-01"; render();',app.context);
    assert.equal(app.element('day-session').hidden,false);
    assert.equal(app.element('finish-day-central').disabled,false);
});


test('the timer sounds once at zero and still allows a goal before the user finishes', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    vm.runInContext(`globalThis.tones = 0; globalThis.AudioContext = class {constructor(){this.state='running';this.currentTime=0;this.destination={};} resume(){return Promise.resolve();} createOscillator(){return {frequency:{value:0},connect(){},start(){tones++;},stop(){}};} createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){}},connect(){}};}}; enableAlarm(); state.current.elapsed = 601; render(); renderClock();`,app.context);
    assert.equal(vm.runInContext('tones',app.context),3);
    assert.equal(app.element('clock').textContent,'00:00');
    assert.equal(app.element('add-goal').disabled,false);
    assert.match(app.element('clock-caption').textContent,/finalize quando a bola sair/);
    assert.ok(vm.runInContext('state.current',app.context));
    const scorer = vm.runInContext('state.current.teams.a[0]',app.context);
    await app.click('add-goal'); await app.trigger({goalTeam:'a'}); await app.trigger({goalScorer:scorer}); await app.submit('goal-form');
    assert.equal(vm.runInContext('state.current.goals[0].time',app.context),601);
});

test('the free match button starts an unlimited count up timer', async () => {
    const app = await harness();
    await app.click('new-match'); await app.submit('setup-form');
    await app.click('free-match');
    assert.equal(vm.runInContext('state.current.free',app.context),true);
    assert.ok(vm.runInContext('state.current.startedAt',app.context));
    assert.equal(app.element('clock').textContent,'00:00');
    vm.runInContext('state.current.startedAt = null; state.current.elapsed = 1205; render();',app.context);
    assert.equal(app.element('clock').textContent,'20:05');
    assert.equal(app.element('add-goal').disabled,false);
});


test('the present players card lists arrivals and excludes registered players still on the way', async () => {
    const app = await harness();
    vm.runInContext('days[0].arrived_players = [state.players[0].id]; render();',app.context);
    assert.equal(app.element('present-players-count').textContent,1);
    assert.match(app.element('present-players').innerHTML,/Jogador 1</);
    assert.doesNotMatch(app.element('present-players').innerHTML,/Jogador 2</);
});
