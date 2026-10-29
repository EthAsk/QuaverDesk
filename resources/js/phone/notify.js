// Desktop notifications for calls. Shown through the service worker so they can
// carry Answer / Decline buttons and bring the window with the phone forward when clicked.

const fallbacks = new Map();

export function supported() {
    return 'Notification' in window;
}

export function permission() {
    return supported() ? Notification.permission : 'unsupported';
}

export async function askPermission() {
    if (!supported()) return 'unsupported';
    return Notification.requestPermission();
}

export async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return null;
    try {
        return await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    } catch {
        return null;
    }
}

async function registration() {
    if (!('serviceWorker' in navigator)) return null;
    return (await navigator.serviceWorker.getRegistration('/').catch(() => null)) ?? null;
}

/**
 * @param {{title: string, body: string, tag: string, call?: boolean}} n
 *        call: an incoming call (stays on screen, with Answer and Decline)
 */
export async function notify({ title, body, tag, call = false }) {
    if (permission() !== 'granted') return;

    const options = {
        body,
        tag,
        renotify: true,
        requireInteraction: call,
        icon: '/icons/icon-192.png',
        badge: '/icons/badge-72.png',
        data: { tag, url: location.pathname }, // the window ringing: the phone's own, or the app with it docked
        actions: call ? [{ action: 'answer', title: 'Answer' }, { action: 'decline', title: 'Decline' }] : [],
    };

    const reg = await registration();
    if (reg) {
        try {
            await reg.showNotification(title, options);
            return;
        } catch {
            // fall through to a plain notification
        }
    }

    try {
        const n = new Notification(title, { body, tag, icon: options.icon, requireInteraction: call });
        n.onclick = () => {
            window.focus();
            n.close();
        };
        fallbacks.set(tag, n);
    } catch {
        // Notifications unavailable here (e.g. some mobile browsers without a service worker).
    }
}

export async function closeNotification(tag) {
    fallbacks.get(tag)?.close();
    fallbacks.delete(tag);
    const reg = await registration();
    const open = reg ? await reg.getNotifications({ tag }).catch(() => []) : [];
    open.forEach((n) => n.close());
}

/** App icon badge (installed app only): number of missed calls. */
export function setBadge(count) {
    try {
        if (count > 0) navigator.setAppBadge?.(count)?.catch?.(() => {});
        else navigator.clearAppBadge?.()?.catch?.(() => {});
    } catch {
        // not supported
    }
}
