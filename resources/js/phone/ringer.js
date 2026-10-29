// UK ring cadence (400+450 Hz, 0.4 s on, 0.2 s off, 0.4 s on, 2 s off) made with
// Web Audio as one looping buffer, so it keeps time even in a background window
// where timers are slowed down.

export function createRinger() {
    let ctx = null;
    let buffer = null;
    let source = null;

    const context = () => {
        if (!ctx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return null;
            ctx = new AudioContext();
        }
        return ctx;
    };

    const makeBuffer = (c) => {
        const rate = c.sampleRate;
        const out = c.createBuffer(1, rate * 3, rate);
        const data = out.getChannelData(0);
        const bursts = [[0, 0.4], [0.6, 1.0]];
        const fade = 0.01;

        for (let i = 0; i < data.length; i++) {
            const t = i / rate;
            const burst = bursts.find(([from, to]) => t >= from && t < to);
            if (!burst) continue;
            const edge = Math.min(t - burst[0], burst[1] - t);
            const level = Math.min(1, edge / fade);
            data[i] = 0.12 * level * (Math.sin(2 * Math.PI * 400 * t) + Math.sin(2 * Math.PI * 450 * t));
        }
        return out;
    };

    return {
        /** Call from a click or key press: browsers only allow sound after one. */
        unlock() {
            context()?.resume().catch(() => {});
        },

        audible() {
            return ctx !== null && ctx.state === 'running';
        },

        start() {
            const c = context();
            if (!c || source) return;
            c.resume().catch(() => {});
            buffer ??= makeBuffer(c);
            source = c.createBufferSource();
            source.buffer = buffer;
            source.loop = true;
            source.connect(c.destination);
            source.start();
        },

        stop() {
            if (!source) return;
            try {
                source.stop();
            } catch {
                // already stopped
            }
            source.disconnect();
            source = null;
        },

        /** Ring on a chosen speaker where the browser supports it. */
        async setSink(deviceId) {
            const c = context();
            if (c && typeof c.setSinkId === 'function') {
                await c.setSinkId(deviceId || '').catch(() => {});
            }
        },
    };
}
