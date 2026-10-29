// Shared by the phone window and the rest of the app.

/** BroadcastChannel name: the phone window announces its status here and takes dial requests. */
export const CHANNEL = 'qd-phone';

/** Web Lock held by the one window that runs the phone. */
export const LOCK = 'qd-phone';

/** Name of the phone's own window, so links reuse it. */
export const WINDOW_NAME = 'qd-phone';
export const WINDOW_FEATURES = 'popup,width=340,height=580';

/** Per-device preferences. Storage can be blocked; never let that break calling. */
export const prefs = {
    get(key, fallback = null) {
        try {
            return localStorage.getItem(`qd.phone.${key}`) ?? fallback;
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try {
            localStorage.setItem(`qd.phone.${key}`, value);
        } catch {
            // Private window or storage blocked: the setting lasts for this page only.
        }
    },
};

/**
 * What can be dialled: digits, *, #, + and colleague identities like user-12.
 * Accepts tel: and sip: links ("tel:+44%2020%207946%200000", "sip:user-12@host").
 */
export function cleanNumber(value) {
    let text = String(value ?? '').trim();
    try {
        text = decodeURIComponent(text);
    } catch {
        // not encoded
    }
    text = text.replace(/^(tel|sips?):/i, '').replace(/[;@?].*$/, '');
    return text.replace(/[^0-9*#+a-z-]/gi, '').toLowerCase().slice(0, 40);
}
