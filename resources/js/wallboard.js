// Live wallboard: polls /wallboard/data and redraws. Built with DOM methods only (no innerHTML),
// so names and numbers from calls can never become markup.
const root = document.querySelector('[data-wallboard]');

if (root) {
    const url = root.dataset.url;
    const every = Math.max(3, Number(root.dataset.refresh || 5)) * 1000;
    let data = JSON.parse(root.querySelector('script[type="application/json"]').textContent);
    let receivedAt = Date.now();
    let failures = 0;

    const el = (tag, attrs = {}, ...children) => {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') node.className = v;
            else node.setAttribute(k, v);
        }
        for (const c of children.flat()) {
            if (c !== null && c !== undefined) node.append(c instanceof Node ? c : document.createTextNode(String(c)));
        }
        return node;
    };
    const clock = (s) => {
        s = Math.max(0, Math.floor(s));
        const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
        return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(sec).padStart(2, '0');
    };
    const since = () => (Date.now() - receivedAt) / 1000; // live timers keep ticking between polls
    const fill = (name, ...nodes) => root.querySelector(`[data-part="${name}"]`).replaceChildren(...nodes);

    const monitorNote = el('div', { class: 'small muted', 'aria-live': 'polite' });

    function monitorButton(callId, mode) {
        const button = el('button', { type: 'button', class: 'btn ghost small' }, mode[0].toUpperCase() + mode.slice(1));
        button.addEventListener('click', async () => {
            if (mode === 'barge' && !window.confirm('Join this call? Both people will hear you.')) return;
            button.disabled = true;
            try {
                const res = await fetch(`${root.dataset.monitor}/${callId}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': root.dataset.csrf, 'Content-Type': 'application/json', Accept: 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ mode }),
                });
                monitorNote.textContent = (await res.json()).message || '';
            } catch {
                monitorNote.textContent = 'Could not reach the phone system.';
            }
            setTimeout(() => { button.disabled = false; }, 3000);
        });
        return button;
    }

    const tile = (label, value, tone = '') => el('div', { class: 'wb-tile ' + tone }, el('div', { class: 'wb-value' }, value), el('div', { class: 'wb-label' }, label));

    function draw() {
        const t = data.totals;
        fill('totals',
            tile('Live calls', data.live.length),
            tile('Waiting', data.queues.reduce((n, q) => n + q.waiting, 0), data.queues.some((q) => q.waiting > 0) ? 'warn' : ''),
            tile('Calls in today', t.inbound),
            tile('Missed today', t.missed, t.missed > 0 ? 'bad' : ''),
            tile('Calls out today', t.outbound),
            tile('Talk time today', clock(t.talk_seconds)),
        );

        fill('queues', data.queues.length === 0
            ? el('p', { class: 'muted' }, 'No call queues.')
            : el('table', {},
                el('thead', {}, el('tr', {}, ['Queue', 'Waiting', 'Longest wait', 'Agents (on a call)', 'Answered', 'Abandoned', 'Overflowed'].map((h) => el('th', {}, h)))),
                el('tbody', {}, data.queues.map((q) => el('tr', { class: q.waiting > 0 ? 'wb-hot' : '' },
                    el('td', {}, el('strong', {}, q.name)),
                    el('td', {}, q.waiting),
                    el('td', {}, q.waiting ? clock(q.longest_wait + since()) : '—'),
                    el('td', {}, q.agents + (q.agents_busy === null ? '' : ` (${q.agents_busy})`)),
                    el('td', {}, q.answered), el('td', {}, q.abandoned), el('td', {}, q.overflowed),
                )))));

        fill('live', data.live.length === 0
            ? el('p', { class: 'muted' }, 'No calls right now.')
            : el('table', {},
                el('thead', {}, el('tr', {}, ['', 'From', 'To', 'Who', 'Time', root.dataset.monitor ? 'Listen in' : null].filter((h) => h !== null).map((h) => el('th', {}, h)))),
                el('tbody', {}, data.live.map((c) => el('tr', {},
                    el('td', {}, el('span', { class: 'pill' + (c.direction === 'inbound' ? '' : ' off') }, c.direction === 'inbound' ? 'In' : c.direction === 'outbound' ? 'Out' : 'Internal')),
                    el('td', {}, c.from || '—'), el('td', {}, c.to || '—'), el('td', {}, c.person || '—'),
                    el('td', { class: 'wb-num' }, clock(c.seconds + since())),
                    root.dataset.monitor ? el('td', {}, ['listen', 'whisper', 'barge'].map((mode) => monitorButton(c.id, mode))) : null,
                )))));

        if (root.dataset.monitor) root.querySelector('[data-part="live"]').append(monitorNote);

        const label = { busy: 'On a call', free: 'Free', unknown: '—' };
        fill('people', el('div', { class: 'wb-people' }, data.people.map((p) => el('div', { class: 'wb-person wb-' + p.status },
            el('div', {}, el('strong', {}, p.name), el('span', { class: 'muted small' }, ' ext ' + p.extension)),
            el('div', { class: 'small' }, label[p.status], p.queues.length ? ' · in ' + p.queues.join(', ') : '', ' · ', p.calls_today, ' today'),
        ))));

        const stale = failures > 0;
        fill('status', stale ? 'Not updating: trying again…' : (data.voice_server ? 'Live' : 'Live, but the voice server can\'t say who is on a call'));
        root.querySelector('[data-part="status"]').className = 'pill' + (stale || !data.voice_server ? ' warn' : '');
    }

    async function poll() {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (res.status === 401 || res.status === 419 || res.redirected) {
                window.location.reload(); // signed out: show the sign-in page
                return;
            }
            if (!res.ok) throw new Error(String(res.status));
            data = await res.json();
            receivedAt = Date.now();
            failures = 0;
        } catch {
            failures++;
        }
        draw();
    }

    draw();
    const timer = setInterval(poll, every);
    const tick = setInterval(draw, 1000);
    document.addEventListener('turbo:before-visit', () => { clearInterval(timer); clearInterval(tick); }, { once: true });

    root.querySelector('[data-fullscreen]')?.addEventListener('click', () => {
        if (document.fullscreenElement) document.exitFullscreen();
        else root.requestFullscreen?.();
    });
}
