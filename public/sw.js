// QuaverDesk service worker: call notifications and an offline page.
// Pages are never cached: everything else always comes from the server.

const CACHE = 'qd-offline-v2';
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (key !== CACHE) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

// Only page loads that fail (no network) get the offline page.
self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.mode !== 'navigate' || request.method !== 'GET') return;
    event.respondWith(fetch(request).catch(async () => (await caches.match(OFFLINE)) || Response.error()));
});

// A click on a call notification (or its Answer / Decline button) goes to the window
// running the phone: its own window, or the app with the phone docked. Every window
// hears about it; only the one with that call acts on it.
self.addEventListener('notificationclick', (event) => {
    const { action, notification } = event;
    const data = notification.data || {};
    notification.close();

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const path = (w) => new URL(w.url).pathname;
        // Most recently used first; prefer the window that rang, then the phone's own window.
        const target = windows.find((w) => path(w) === data.url) ?? windows.find((w) => path(w) === '/phone') ?? windows[0];

        windows.forEach((w) => w.postMessage({ type: 'notification', action: action || 'open', tag: data.tag }));
        if (target) {
            if (action !== 'decline') await target.focus().catch(() => {});
        } else if (action !== 'decline') {
            await self.clients.openWindow(data.url || '/phone');
        }
    })());
});
