// The rest of the app talks to the phone (docked in the corner, or in its own
// window): shows its status, pops it out, sends click-to-call requests, and
// pops it out after signing in when this computer wants that.

import { CHANNEL, WINDOW_FEATURES, WINDOW_NAME, cleanNumber, prefs } from './phone/shared';
import { askPermission, permission, registerServiceWorker } from './phone/notify';

const channel = 'BroadcastChannel' in window ? new BroadcastChannel(CHANNEL) : null;
let status = null; // last status from the live phone, or null
let pendingDial = null;
let installPrompt = null;

const running = () => status !== null && status.state !== 'closed';
const dock = () => document.querySelector('#phone[data-docked]');

function renderStatus() {
    const text = running() ? status.text : (dock() ? 'Starting…' : 'Not open');
    const state = running() ? status.state : 'closed';
    document.querySelectorAll('[data-phone-text]').forEach((el) => { el.textContent = text; });
    document.querySelectorAll('[data-phone-dot]').forEach((el) => { el.dataset.state = state; });
}

function popOut() {
    if (running() && ['in-call', 'ringing'].includes(status.state)) return false; // moving would drop the call
    if (!/^qd-app-/.test(window.name)) window.name = `qd-app-${Math.random().toString(36).slice(2, 10)}`;
    return window.open(`/phone?popout=1&app=${window.name}`, WINDOW_NAME, WINDOW_FEATURES) !== null;
}

/** Show the phone: open the dock, or pop it out where there is no dock. */
function showPhone() {
    const docked = dock();
    if (docked) docked.dispatchEvent(new CustomEvent('phone:show'));
    else if (!running()) popOut();
}

/** Click-to-call: the live phone dials straight away; otherwise dial once it's ready. */
function dial(number) {
    const to = cleanNumber(number);
    if (!to) return;
    showPhone();
    if (running() && status.state === 'ready') {
        channel?.postMessage({ type: 'dial', number: to });
        return;
    }
    if (running() && status.state !== 'connecting') return; // busy on a call
    pendingDial = { number: to, until: Date.now() + 20000 };
    if (!dock() && !running() && !popOut()) {
        pendingDial = null;
        window.location.href = `/phone?dial=${encodeURIComponent(to)}`;
    }
}

channel?.addEventListener('message', ({ data }) => {
    if (data?.type !== 'status' || (!data.active && data.state !== 'closed')) return;
    status = data;
    renderStatus();
    if (pendingDial && data.state === 'ready') {
        if (Date.now() < pendingDial.until) channel.postMessage({ type: 'dial', number: pendingDial.number });
        pendingDial = null;
    }
});

document.addEventListener('click', (event) => {
    const dialBtn = event.target.closest('[data-dial]');
    if (dialBtn && !dialBtn.closest('#phone')) { // the phone handles its own buttons
        event.preventDefault();
        dial(dialBtn.dataset.dial);
        return;
    }
    if (event.target.closest('[data-phone-open]')) {
        event.preventDefault();
        showPhone();
    }
    if (event.target.closest('[data-phone-popout]')) {
        event.preventDefault();
        if (!popOut()) {
            document.querySelectorAll('[data-phone-text]').forEach((el) => {
                el.textContent = running() && status.state === 'in-call'
                    ? 'Finish the call first: moving the phone would end it'
                    : 'Your browser blocked the window: allow pop-ups for this site';
            });
        }
    }
    if (event.target.closest('[data-install]') && installPrompt) {
        installPrompt.prompt();
        installPrompt = null;
        document.querySelectorAll('[data-install]').forEach((el) => { el.hidden = true; });
    }
    if (event.target.closest('[data-notify-enable]')) {
        askPermission().then(renderNotify);
    }
    const tel = event.target.closest('[data-tel-handler]');
    if (tel) {
        try {
            navigator.registerProtocolHandler('tel', `${location.origin}/phone?dial=%s`);
            tel.textContent = 'Asked your browser';
        } catch {
            tel.textContent = 'Not supported in this browser';
        }
        tel.disabled = true;
    }
});

function renderNotify() {
    const p = permission();
    document.querySelectorAll('[data-notify-state]').forEach((el) => {
        el.textContent = {
            granted: 'On',
            denied: 'Blocked: allow notifications for this site in your browser settings',
            default: 'Off',
            unsupported: 'Not supported by this browser',
        }[p];
    });
    document.querySelectorAll('[data-notify-enable]').forEach((el) => { el.hidden = p !== 'default'; });
}

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installPrompt = event;
    document.querySelectorAll('[data-install]').forEach((el) => { el.hidden = false; });
});

// Each page (Turbo replaces the page, not the window).
document.addEventListener('turbo:load', () => {
    renderStatus();
    renderNotify();
    document.querySelectorAll('[data-popout-pref]').forEach((el) => {
        el.checked = prefs.get('popout', '0') === '1';
        el.addEventListener('change', () => prefs.set('popout', el.checked ? '1' : '0'));
    });
    if (!('registerProtocolHandler' in navigator)) {
        document.querySelectorAll('[data-tel-handler]').forEach((el) => { el.closest('.field').hidden = true; });
    }
    document.querySelectorAll('[data-install]').forEach((el) => { el.hidden = !installPrompt; });
});

// Ask the live phone for its status.
channel?.postMessage({ type: 'ping' });

// Just signed in: pop the phone out if this computer wants that. Browsers may
// block a window that wasn't opened by a click; the docked phone works anyway.
if (document.body.dataset.phoneAutostart !== undefined && prefs.get('popout', '0') === '1') {
    setTimeout(popOut, 800);
}

registerServiceWorker();
