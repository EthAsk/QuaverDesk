// The phone: signs in to the voice server and handles calls. It runs either
// in its own window (/phone, also the installed app) or docked in the corner
// of the main app (#phone[data-docked]), where Turbo keeps it alive between pages.
//
// Only one window per browser runs the phone (a Web Lock), so calls ring once.
// "Pop out" opens /phone?popout=1, which takes the phone over; closing that
// window hands it straight back to the dock. The live phone announces its
// status on a BroadcastChannel; the rest of the app shows that status and
// sends click-to-call requests the same way.

import JsSIP from 'jssip';
import { CHANNEL, LOCK, WINDOW_FEATURES, WINDOW_NAME, cleanNumber, prefs } from './shared';
import { createRinger } from './ringer';
import {
    askPermission, closeNotification, notify, permission, registerServiceWorker, setBadge, supported as notificationsSupported,
} from './notify';

export function boot(root) {
    if (root.dataset.booted) return;
    root.dataset.booted = '1';

    const docked = root.dataset.docked !== undefined;
    const poppedOut = !docked && new URLSearchParams(location.search).has('popout');
    const $ = (id) => document.getElementById(id);
    const ui = {
        number: $('number'),
        state: $('state'),
        dot: $('status-dot'),
        call: $('call'),
        clear: $('clear'),
        mute: $('mute'),
        hold: $('hold'),
        transfer: $('transfer'),
        park: $('park'),
        xfer: $('xfer'),
        xferText: $('xfer-text'),
        xferNow: $('xfer-now'),
        xferAsk: $('xfer-ask'),
        xferCancel: $('xfer-cancel'),
        timer: $('timer'),
        caller: $('caller'),
        callerName: $('caller-name'),
        hint: $('hint'),
        elsewhere: $('elsewhere'),
        elsewhereText: $('elsewhere-text'),
        takeover: $('takeover'),
        remote: $('remote-audio'),
        dnd: $('pref-dnd'),
        popoutPref: $('pref-popout'),
        dockToggle: $('dock-toggle'),
        dockBody: $('dock-body'),
        dockPopout: $('dock-popout'),
        notifyState: $('notify-state'),
        notifyBtn: $('notify-enable'),
        mic: $('pref-mic'),
        speaker: $('pref-speaker'),
    };
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel(CHANNEL) : null;
    const ringer = createRinger();

    // Colleague names, so calls show "Sam Patel" rather than "user-12".
    const names = new Map([...root.querySelectorAll('[data-dial][data-name]')].map((el) => [el.dataset.dial, el.dataset.name]));

    let ua = null;
    let active = false; // this window holds the lock and runs the phone
    let releaseLock = null;
    let registered = false;
    let session = null;
    let incoming = false;
    let held = false;
    let emergency = false; // 999/112: never transferred or parked
    let xfer = null; // null, 'choosing' (typing where to) or 'consulting' (talking to them first)
    let consult = null; // the call to the person being asked, during "Talk first"
    let handedOff = ''; // what to show when the call ends because it was transferred or parked
    const ICE_WAIT_MS = 1500;
    let pcConfig = { iceServers: [] };
    let status = { state: 'connecting', text: 'Connecting…' };
    let callStarted = null;
    let timerId = null;
    let missed = 0;
    let otherBusy = false;
    let lastRekey = 0; // when the phone last fetched a new password after being refused
    let waiting = null; // docked: a queued lock request, to take the phone back when the other window closes

    // ---- status -----------------------------------------------------------

    const broadcast = () => channel?.postMessage({ type: 'status', ...status, active });

    function setStatus(state, text) {
        status = { state, text };
        ui.state.textContent = text;
        ui.dot.dataset.state = state;
        root.dataset.state = state;
        if (active) broadcast();
    }

    function readyText() {
        return ui.dnd.checked ? 'Do not disturb' : 'Ready';
    }

    function showHint(text) {
        ui.hint.textContent = text;
        ui.hint.hidden = !text;
    }

    const muted = () => session !== null && session.isMuted().audio;

    function render() {
        const busy = session !== null;
        ui.call.textContent = incoming ? 'Answer' : busy ? 'Hang up' : 'Call';
        ui.call.classList.toggle('hang', busy && !incoming);
        ui.call.disabled = !registered || (!busy && cleanNumber(ui.number.value) === '');
        ui.clear.textContent = incoming ? 'Decline' : 'Clear';
        ui.mute.disabled = !busy || incoming;
        ui.mute.textContent = muted() ? 'Unmute' : 'Mute';
        ui.mute.setAttribute('aria-pressed', muted() ? 'true' : 'false');
        ui.hold.disabled = !busy || incoming || xfer !== null;
        ui.hold.textContent = held ? 'Resume' : 'Hold';
        ui.hold.setAttribute('aria-pressed', held ? 'true' : 'false');
        const canHandOff = busy && !incoming && !emergency && xfer === null && callStarted !== null;
        ui.transfer.disabled = !canHandOff;
        ui.park.disabled = !canHandOff;
        ui.xfer.hidden = xfer === null;
        ui.xferAsk.hidden = xfer === 'consulting';
        ui.xferNow.textContent = xfer === 'consulting' ? 'Complete transfer' : 'Transfer now';
        ui.xferNow.disabled = xfer === 'choosing' && cleanNumber(ui.number.value) === '';
        ui.xferAsk.disabled = ui.xferNow.disabled;
        // Digits typed during a call are sent as tones, except while typing where to transfer it.
        ui.number.readOnly = busy && xfer !== 'choosing';
        if (ui.dockPopout) ui.dockPopout.disabled = busy; // moving windows would drop the call
    }

    // Ringing shows in the tab title; put it back afterwards (the page may have changed since).
    function ringTitle(who) {
        document.title = `📞 ${who} · ${document.title}`;
    }
    function clearRingTitle() {
        if (document.title.startsWith('📞 ')) document.title = document.title.slice(document.title.indexOf(' · ') + 3);
    }

    // ---- the dock (main app window) -----------------------------------------

    function setDockOpen(open) {
        if (!docked) return;
        ui.dockBody.hidden = !open;
        root.dataset.open = open ? 'true' : 'false';
        ui.dockToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        prefs.set('dock', open ? 'open' : 'closed');
    }

    // Popped out: "Back to …" closes this window; the dock in the app takes the phone back.
    $('dock-back')?.addEventListener('click', () => {
        if (session && !window.confirm('Closing this window ends your call. Close it?')) return;
        // Bring the app window forward, even out of the Dock when minimised: browsers
        // only let a page raise a window it opened, or one it targets by name (as here).
        // If that window has gone, the name opens a new one, which then loads the app.
        const name = new URLSearchParams(location.search).get('app');
        const opener = window.opener && !window.opener.closed ? window.opener : null;
        const found = /^qd-app-[a-z0-9]+$/.test(name ?? '') ? window.open('', name) : null;
        let blank = false;
        try {
            blank = found !== null && found.location.href === 'about:blank';
        } catch {
            // not readable: it is the app, already showing a page
        }
        if (blank && opener) {
            found.close(); // the name wasn't found; don't leave a second copy of the app
            opener.focus();
        } else if (blank) {
            found.location.replace('/dashboard'); // the app window has gone: open it again
        } else if (!found) {
            if (opener) opener.focus(); else window.open('/dashboard', '_blank');
        }
        window.close();
    });

    if (docked) {
        setDockOpen(prefs.get('dock', 'closed') === 'open');
        ui.dockToggle.addEventListener('click', () => setDockOpen(ui.dockBody.hidden));
        root.addEventListener('phone:show', () => setDockOpen(true));
        ui.dockPopout.addEventListener('click', () => {
            if (session) return;
            // Name this window so the pop-out can bring it back to the front later.
            if (!/^qd-app-/.test(window.name)) window.name = `qd-app-${Math.random().toString(36).slice(2, 10)}`;
            if (window.open(`/phone?popout=1&app=${window.name}`, WINDOW_NAME, WINDOW_FEATURES) === null) {
                setDockOpen(true);
                showHint('Your browser blocked the pop-out. Allow pop-ups for this site, or keep using the phone here.');
            }
        });
    }

    function startTimer() {
        callStarted = Date.now();
        clearInterval(timerId);
        const tick = () => {
            const s = Math.floor((Date.now() - callStarted) / 1000);
            ui.timer.textContent = `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
        };
        tick();
        timerId = setInterval(tick, 1000);
    }

    function stopTimer() {
        clearInterval(timerId);
        timerId = null;
        callStarted = null;
        ui.timer.textContent = '';
    }

    // ---- settings and devices -------------------------------------------

    const audioConstraints = () => {
        const mic = prefs.get('mic');
        return { audio: mic ? { deviceId: mic } : true, video: false };
    };

    async function applySpeaker() {
        const id = prefs.get('speaker', '');
        if (typeof ui.remote.setSinkId === 'function') await ui.remote.setSinkId(id).catch(() => {});
        await ringer.setSink(id);
    }

    async function listDevices() {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        const devices = await navigator.mediaDevices.enumerateDevices().catch(() => []);
        const fill = (select, kind, saved) => {
            const options = devices.filter((d) => d.kind === kind && d.deviceId !== 'default' && d.deviceId !== '');
            select.replaceChildren(new Option('System default', ''));
            options.forEach((d, i) => select.add(new Option(d.label || `${kind === 'audioinput' ? 'Microphone' : 'Speaker'} ${i + 1}`, d.deviceId)));
            select.value = options.some((d) => d.deviceId === saved) ? saved : '';
        };
        fill(ui.mic, 'audioinput', prefs.get('mic', ''));
        if (typeof ui.remote.setSinkId === 'function') {
            fill(ui.speaker, 'audiooutput', prefs.get('speaker', ''));
        } else {
            ui.speaker.closest('.field').hidden = true;
        }
    }

    function renderNotifyState() {
        const p = permission();
        ui.notifyState.textContent = {
            granted: 'On',
            denied: 'Blocked: allow notifications for this site in your browser settings',
            default: 'Off',
            unsupported: 'Not supported by this browser',
        }[p];
        ui.notifyBtn.hidden = p !== 'default';
    }

    ui.dnd.checked = prefs.get('dnd') === '1';
    ui.popoutPref.checked = prefs.get('popout', '0') === '1';
    ui.dnd.addEventListener('change', () => {
        prefs.set('dnd', ui.dnd.checked ? '1' : '0');
        if (registered && !session) setStatus('ready', readyText());
    });
    ui.popoutPref.addEventListener('change', () => prefs.set('popout', ui.popoutPref.checked ? '1' : '0'));
    ui.mic.addEventListener('change', () => prefs.set('mic', ui.mic.value));
    ui.speaker.addEventListener('change', () => {
        prefs.set('speaker', ui.speaker.value);
        applySpeaker();
    });
    ui.notifyBtn.addEventListener('click', async () => {
        await askPermission();
        renderNotifyState();
        if (permission() === 'granted') showHint('');
    });
    navigator.mediaDevices?.addEventListener?.('devicechange', listDevices);

    // Tabs: Keypad / Colleagues / Settings.
    root.querySelectorAll('[data-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            root.querySelectorAll('[data-tab]').forEach((t) => t.setAttribute('aria-selected', t === tab ? 'true' : 'false'));
            root.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== tab.dataset.tab; });
            if (tab.dataset.tab === 'settings') listDevices();
        });
    });

    // Browsers only play sound after the person has clicked or typed once.
    const unlock = () => {
        ringer.unlock();
        if (ui.hint.dataset.kind === 'sound') showHint('');
    };
    window.addEventListener('pointerdown', unlock);
    window.addEventListener('keydown', unlock);

    // ---- calls ------------------------------------------------------------

    function callerLabel(s) {
        const user = s.remote_identity.uri.user || '';
        return names.get(user) || s.remote_identity.display_name || user || 'Unknown caller';
    }

    function attachAudio(s) {
        s.connection.addEventListener('track', (e) => {
            if (e.streams[0]) ui.remote.srcObject = e.streams[0];
        });
    }

    // Back to hearing a call we already had (after "Talk first").
    function listenTo(s) {
        const tracks = s?.connection?.getReceivers().map((r) => r.track).filter(Boolean) ?? [];
        if (tracks.length) ui.remote.srcObject = new MediaStream(tracks);
    }

    const completedElsewhere = (e) => /completed elsewhere/i.test(e.message?.getHeader?.('Reason') || '');

    function failText(e) {
        const C = JsSIP.C.causes;
        return {
            [C.BUSY]: 'Busy',
            [C.REJECTED]: 'Declined',
            [C.CANCELED]: 'Cancelled',
            [C.NOT_FOUND]: 'Number not found',
            [C.UNAVAILABLE]: 'Not available',
            [C.NO_ANSWER]: 'No answer',
            [C.USER_DENIED_MEDIA_ACCESS]: 'Microphone blocked: allow it in the address bar',
            [C.CONNECTION_ERROR]: 'Connection lost',
        }[e.cause] || `Call failed (${e.cause})`;
    }

    function wireSession(s, tag) {
        const finish = (text) => {
            if (consult) consult.terminate();
            session = null;
            incoming = false;
            held = false;
            emergency = false;
            xfer = null;
            consult = null;
            if (handedOff) text = handedOff;
            handedOff = '';
            ringer.stop();
            closeNotification(tag);
            stopTimer();
            ui.remote.srcObject = null;
            ui.caller.hidden = true;
            clearRingTitle();
            setStatus(registered ? 'ready' : 'offline', registered ? `${text} · ${readyText()}` : text);
            render();
        };

        // The call waits until the browser has finished finding its network addresses, which on some
        // networks (a VPN that drops STUN) never happens. The voice server learns the real address
        // from the audio itself, so go shortly after the first ones.
        let iceTimer = null;
        s.on('icecandidate', (e) => {
            if (iceTimer === null) iceTimer = setTimeout(e.ready, ICE_WAIT_MS);
        });
        s.on('progress', () => { if (!incoming) setStatus('in-call', 'Ringing…'); });
        s.on('accepted', () => {
            incoming = false;
            ringer.stop();
            closeNotification(tag);
            startTimer();
            setStatus('in-call', 'Connected');
            render();
        });
        s.on('ended', () => finish('Call ended'));
        s.on('failed', (e) => {
            const wasIncoming = incoming;
            if (wasIncoming && e.originator === 'remote' && !completedElsewhere(e)) {
                missedCall(callerLabel(s));
                finish('Missed call');
                return;
            }
            finish(wasIncoming && completedElsewhere(e) ? 'Answered on another phone' : failText(e));
        });
        s.on('muted', render);
        s.on('unmuted', render);
        s.on('hold', () => { held = true; if (!xfer) setStatus('in-call', 'On hold'); render(); });
        s.on('unhold', () => { held = false; setStatus('in-call', 'Connected'); render(); });
    }

    // ---- transfer and park ---------------------------------------------------
    // The voice server does the work (SIP REFER): it checks where the call may go,
    // then hangs up this phone's side.

    const sipUri = (to) => `sip:${to}@${ua.configuration.uri.host}`;

    function handOff(target, options, text) {
        handedOff = text;
        session.refer(target, {
            ...options,
            eventHandlers: {
                requestFailed: () => {
                    handedOff = '';
                    setStatus('in-call', 'The call could not be transferred');
                    render();
                },
            },
        });
        setStatus('in-call', 'Transferring…');
    }

    function startTransfer() {
        if (!session || incoming || emergency) return;
        xfer = 'choosing';
        ui.number.value = '';
        ui.xferText.textContent = 'Type the number or extension to transfer to.';
        render();
        ui.number.focus();
    }

    function cancelTransfer() {
        if (consult) consult.terminate();
        consult = null;
        xfer = null;
        if (session && held) session.unhold();
        listenTo(session);
        render();
    }

    function transferNow() {
        if (!session) return;
        if (xfer === 'consulting' && consult) {
            handOff(consult.remote_identity.uri.toString(), { replaces: consult }, 'Transferred');
            return;
        }
        const to = cleanNumber(ui.number.value);
        if (to) handOff(sipUri(to), {}, `Transferred to ${names.get(to) || to}`);
    }

    function talkFirst() {
        const to = cleanNumber(ui.number.value);
        if (!session || !to) return;
        xfer = 'consulting';
        ui.xferText.textContent = `Calling ${names.get(to) || to}… The caller is on hold.`;
        session.hold();
        const c = ua.call(sipUri(to), { mediaConstraints: audioConstraints(), pcConfig });
        consult = c;
        attachAudio(c);
        c.on('accepted', () => { ui.xferText.textContent = `Talking to ${names.get(to) || to}. The caller is on hold.`; });
        const over = () => {
            if (consult !== c) return;
            consult = null;
            xfer = 'choosing';
            ui.xferText.textContent = `${names.get(to) || to} hung up. Try again, or Cancel to go back to the caller.`;
            listenTo(session);
            render();
        };
        c.on('ended', over);
        c.on('failed', over);
        render();
    }

    async function park() {
        if (!session || incoming || emergency) return;
        setStatus('in-call', 'Parking…');
        try {
            const res = await fetch(root.dataset.parkUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'No parking space is free.');
            handOff(sipUri(`**${data.slot}`), {}, `Parked on **${data.slot}. Any phone can dial **${data.slot} to pick it up.`);
        } catch (e) {
            setStatus('in-call', e.message);
        }
    }

    function missedCall(who) {
        missed += 1;
        setBadge(missed);
        notify({ title: 'Missed call', body: who, tag: 'missed' });
    }

    function onIncoming(s) {
        if (session || ui.dnd.checked) {
            s.terminate({ status_code: 486, reason_phrase: 'Busy Here' }); // goes to voicemail
            return;
        }
        const who = callerLabel(s);
        const tag = `call-${s.id}`;
        session = s;
        incoming = true;
        ui.callerName.textContent = who;
        ui.caller.hidden = false;
        setStatus('ringing', `Incoming call · ${who}`);
        ringTitle(who);
        setDockOpen(true);
        wireSession(s, tag);
        s.on('peerconnection', () => attachAudio(s));

        ringer.start();
        if (!ringer.audible()) {
            showHint('Click anywhere in this window once so calls can ring out loud.');
            ui.hint.dataset.kind = 'sound';
        }
        if (document.hidden || !document.hasFocus()) {
            notify({ title: 'Incoming call', body: who, tag, call: true });
        }
        render();
    }

    function answer() {
        if (!session || !incoming) return;
        ringer.stop();
        session.answer({ mediaConstraints: audioConstraints(), pcConfig });
    }

    function decline() {
        if (session && incoming) session.terminate({ status_code: 486, reason_phrase: 'Busy Here' });
    }

    function placeCall(number) {
        const to = cleanNumber(number);
        if (!ua || !registered || session || !to) return;
        ui.number.value = to;
        emergency = ['999', '112'].includes(to);
        setStatus('in-call', 'Calling…');
        try {
            const s = ua.call(`sip:${to}@${ua.configuration.uri.host}`, { mediaConstraints: audioConstraints(), pcConfig });
            session = s;
            wireSession(s, `call-${s.id}`);
            attachAudio(s);
        } catch (e) {
            session = null;
            setStatus('ready', `Could not call: ${e.message}`);
        }
        render();
    }

    // ---- buttons and keyboard ----------------------------------------------

    function press(key) {
        if (xfer === 'choosing') {
            ui.number.value += key;
        } else if (session && !incoming) {
            session.sendDTMF(key); // tones during a call (menus, PINs)
        } else if (!session) {
            ui.number.value += key;
        }
        render();
    }

    root.querySelectorAll('[data-key]').forEach((btn) => btn.addEventListener('click', () => press(btn.dataset.key)));
    // While transferring, a colleague's Call button picks them as the person to transfer to.
    root.querySelectorAll('[data-dial]').forEach((btn) => btn.addEventListener('click', () => {
        if (xfer === 'choosing') {
            ui.number.value = btn.dataset.dial;
            render();
        } else {
            placeCall(btn.dataset.dial);
        }
    }));

    ui.number.addEventListener('input', render);
    ui.number.addEventListener('keydown', (e) => {
        if (xfer === 'choosing') {
            if (e.key === 'Enter') {
                e.preventDefault();
                transferNow();
            }
        } else if (session && !incoming && /^[0-9*#]$/.test(e.key)) {
            e.preventDefault();
            press(e.key);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            ui.call.click();
        }
    });

    ui.clear.addEventListener('click', () => {
        if (incoming) {
            decline();
            return;
        }
        ui.number.value = '';
        render();
        ui.number.focus();
    });

    ui.mute.addEventListener('click', () => {
        if (!session) return;
        if (muted()) session.unmute({ audio: true }); else session.mute({ audio: true });
        render();
    });

    ui.hold.addEventListener('click', () => {
        if (!session || incoming) return;
        if (held) session.unhold(); else session.hold();
    });

    ui.transfer.addEventListener('click', startTransfer);
    ui.park.addEventListener('click', park);
    ui.xferNow.addEventListener('click', transferNow);
    ui.xferAsk.addEventListener('click', talkFirst);
    ui.xferCancel.addEventListener('click', cancelTransfer);

    ui.call.addEventListener('click', () => {
        if (incoming) answer();
        else if (session) session.terminate();
        else placeCall(ui.number.value);
    });

    // Notification buttons arrive from the service worker.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.onmessage = ({ data }) => {
            if (data?.type !== 'notification' || !session || !incoming || data.tag !== `call-${session.id}`) return;
            if (data.action === 'answer') answer();
            if (data.action === 'decline') decline();
        };
    }

    // ---- signing in -------------------------------------------------------

    async function fetchSettings() {
        const res = await fetch(root.dataset.tokenUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (res.status === 401 || res.status === 419) {
            location.reload(); // signed out: the reload goes through sign-in and back here
            return null;
        }
        const body = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(body.message || 'Could not start the phone.');
        return body;
    }

    async function start() {
        active = true;
        ui.elsewhere.hidden = true;
        setStatus('connecting', 'Connecting…');

        let settings;
        try {
            settings = await fetchSettings();
        } catch (e) {
            setStatus('error', e.message);
            return;
        }
        if (!settings) return;
        if (settings.stun_url) pcConfig = { iceServers: [{ urls: settings.stun_url }] };

        ua = new JsSIP.UA({
            sockets: [new JsSIP.WebSocketInterface(settings.websocket_url)],
            uri: settings.uri,
            authorization_user: settings.username,
            password: settings.password,
            register: true,
            register_expires: 300,
            session_timers: false,
            connection_recovery_min_interval: 2,
            connection_recovery_max_interval: 30,
            user_agent: 'QuaverDesk phone',
        });

        ua.on('registered', () => {
            registered = true;
            if (!session) setStatus('ready', readyText());
            render();
        });
        ua.on('unregistered', () => {
            registered = false;
            if (active) setStatus('offline', 'Signed out');
            render();
        });
        ua.on('registrationFailed', (e) => {
            registered = false;
            // The phone's password changes with the account password: fetch the new one (once a minute at most).
            if (e.cause === JsSIP.C.causes.AUTHENTICATION_ERROR && Date.now() - lastRekey > 60000 && !session) {
                lastRekey = Date.now();
                stop();
                start();
                return;
            }
            setStatus('error', `Could not sign in (${e.cause})`);
            render();
        });
        ua.on('disconnected', () => {
            registered = false;
            if (active) setStatus('offline', 'Connection lost · reconnecting…');
            render();
        });
        ua.on('newRTCSession', ({ session: s, originator }) => {
            if (originator === 'remote') onIncoming(s);
        });

        ua.start();
        render();
    }

    function stop() {
        if (ua) {
            ua.stop();
            ua = null;
        }
        registered = false;
        session = null;
        incoming = false;
        ringer.stop();
        render();
    }

    // Background windows can miss a re-registration (sleep, network change).
    // Check when the window wakes or the network comes back.
    function healthCheck() {
        if (ua && ua.isConnected() && !ua.isRegistered()) ua.register();
    }
    window.addEventListener('online', healthCheck);
    document.addEventListener('resume', healthCheck);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        healthCheck();
        missed = 0;
        setBadge(0);
        closeNotification('missed');
    });

    // ---- one phone per browser ---------------------------------------------

    function showElsewhere(text) {
        active = false;
        ui.elsewhereText.textContent = text;
        ui.elsewhere.hidden = false;
        ui.takeover.disabled = otherBusy;
        setStatus('elsewhere', docked ? 'In its own window' : 'Running in another window');
        channel?.postMessage({ type: 'ping' });
    }

    function holdLock(lock) {
        return new Promise((resolve) => { releaseLock = resolve; });
    }

    function run(lock) {
        if (waiting) {
            waiting.abort();
            waiting = null;
        }
        start();
        return holdLock(lock);
    }

    // Docked: queue for the phone, so it comes back here when the other window closes.
    function waitForTurn() {
        if (!docked || waiting || !navigator.locks) return;
        const controller = new AbortController();
        waiting = controller;
        navigator.locks.request(LOCK, { signal: controller.signal }, (lock) => {
            waiting = null;
            return run(lock);
        }).catch(() => {
            if (waiting === controller) waiting = null;
            else if (!controller.signal.aborted) lost();
        });
    }

    function lost() {
        // Another window took over the phone.
        active = false;
        stop();
        if (poppedOut) {
            window.close(); // the phone went back to the main window
            return;
        }
        showElsewhere(docked ? 'Your phone is open in its own window.' : 'Your phone moved to another window.');
        waitForTurn();
    }

    function acquire() {
        if (!navigator.locks) {
            start();
            return;
        }
        // Opened with "Pop out": take the phone from the main window.
        const options = poppedOut ? { steal: true } : { ifAvailable: true };
        navigator.locks.request(LOCK, options, (lock) => {
            if (lock) return run(lock);
            showElsewhere(docked ? 'Your phone is open in its own window.' : 'Your phone is already open in another window or tab.');
            waitForTurn();
            return null;
        }).catch(lost);
    }

    ui.takeover.addEventListener('click', () => {
        navigator.locks.request(LOCK, { steal: true }, run).catch(lost);
    });

    // Docked and showing "open in its own window": take the phone as soon as it's free.
    function retake() {
        if (!docked || active || !navigator.locks) return;
        navigator.locks.request(LOCK, { ifAvailable: true }, (lock) => (lock ? run(lock) : null)).catch(lost);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) setTimeout(retake, 300); });

    channel?.addEventListener('message', ({ data }) => {
        if (!active && data?.type === 'status' && data.state === 'closed') setTimeout(retake, 300);
        if (!active) {
            // Another window runs the phone: don't let this one take over mid-call.
            if (data?.type === 'status' && data.active) {
                otherBusy = data.state === 'in-call' || data.state === 'ringing';
                ui.takeover.disabled = otherBusy;
                ui.takeover.title = otherBusy ? 'Wait until the call in the other window ends' : '';
            }
            return;
        }
        if (data?.type === 'ping') broadcast();
        if (data?.type === 'dial') {
            if (session) broadcast();
            else placeCall(data.number);
        }
    });

    window.addEventListener('beforeunload', (e) => {
        if (session) e.preventDefault(); // ask before closing during a call
    });
    window.addEventListener('pagehide', () => {
        if (!active) return;
        active = false;
        channel?.postMessage({ type: 'status', state: 'closed', text: 'Closed', active: false });
        stop();
        releaseLock?.();
    });

    // A number passed in the address (links, app shortcuts) is only filled in:
    // the person still presses Call, so a link elsewhere can't place calls.
    const prefill = (value) => {
        const number = cleanNumber(value);
        if (number && !session) {
            ui.number.value = number;
            render();
        }
    };
    prefill(new URLSearchParams(location.search).get('dial'));
    window.launchQueue?.setConsumer?.((params) => {
        if (params.targetURL) prefill(new URL(params.targetURL).searchParams.get('dial'));
    });

    registerServiceWorker();
    renderNotifyState();
    if (notificationsSupported() && permission() === 'default') {
        showHint('Turn on notifications in Settings to see calls while this window is in the background.');
    }
    applySpeaker();
    render();
    acquire();
}
