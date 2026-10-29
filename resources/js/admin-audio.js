// Admin audio pages: record or upload a sound (Sounds), and hear each voice (Voice).

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// ---- Sounds: record in the browser, or upload a file -------------------------

function soundForm(form) {
    const recorder = form.querySelector('[data-recorder]');
    const start = form.querySelector('[data-record-start]');
    const stop = form.querySelector('[data-record-stop]');
    const time = form.querySelector('[data-record-time]');
    const preview = form.querySelector('[data-record-preview]');
    const hint = form.querySelector('[data-record-hint]');
    const fileInput = form.querySelector('input[type=file]');
    const error = form.querySelector('[data-sound-error]');
    const save = form.querySelector('[data-sound-save]');

    let media = null;
    let recording = null; // the finished recording (Blob)
    let timer = null;

    if (!window.MediaRecorder || !navigator.mediaDevices?.getUserMedia) {
        recorder.hidden = true;
        form.querySelector('.or-col')?.setAttribute('hidden', '');
    }

    const showError = (text) => {
        error.textContent = text;
        error.hidden = !text;
    };

    start.addEventListener('click', async () => {
        showError('');
        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
        } catch {
            showError('The browser could not use your microphone. Allow microphone access for this site and try again.');
            return;
        }

        const chunks = [];
        media = new MediaRecorder(stream);
        media.addEventListener('dataavailable', (e) => e.data.size && chunks.push(e.data));
        media.addEventListener('stop', () => {
            stream.getTracks().forEach((t) => t.stop());
            recording = new Blob(chunks, { type: media.mimeType || 'audio/webm' });
            if (preview.src) URL.revokeObjectURL(preview.src);
            preview.src = URL.createObjectURL(recording);
            preview.hidden = false;
            hint.textContent = 'Listen back. Happy with it? Add it below, or record again.';
            start.textContent = 'Record again';
            fileInput.value = '';
        });

        const began = Date.now();
        timer = setInterval(() => {
            const s = Math.floor((Date.now() - began) / 1000);
            time.textContent = `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
            if (s >= 600) stop.click();
        }, 250);

        media.start();
        start.hidden = true;
        stop.hidden = false;
        preview.hidden = true;
        time.textContent = '0:00';
    });

    stop.addEventListener('click', () => {
        clearInterval(timer);
        media?.state === 'recording' && media.stop();
        stop.hidden = true;
        start.hidden = false;
    });

    // Choosing a file replaces a recording.
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) {
            recording = null;
            preview.hidden = true;
        }
    });

    form.addEventListener('submit', async (event) => {
        if (media?.state === 'recording') stop.click();
        if (!recording) {
            if (!fileInput.files.length) {
                event.preventDefault();
                showError('Choose a file or record a sound first.');
            }
            return; // a normal upload
        }

        event.preventDefault();
        showError('');
        const data = new FormData(form);
        const ext = recording.type.includes('mp4') ? 'm4a' : recording.type.includes('ogg') ? 'ogg' : 'webm';
        data.set('audio', recording, `recording.${ext}`);

        save.disabled = true;
        save.textContent = 'Saving…';
        try {
            const res = await fetch(form.action, {
                method: 'POST',
                body: data,
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                credentials: 'same-origin',
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                const errors = json.errors ?? {};
                showError(Object.values(errors).flat()[0] ?? json.message ?? 'Could not save the recording.');
                return;
            }
            window.location.assign(json.redirect);
        } catch {
            showError('Could not reach the server. Check your connection and try again.');
        } finally {
            save.disabled = false;
            save.textContent = 'Add sound';
        }
    });
}

// ---- Voice: hear each voice before choosing -----------------------------------

function voiceForm(form) {
    const sample = form.querySelector('[data-voice-sample]');
    const speed = form.querySelector('[data-voice-speed]');
    const speedOut = form.querySelector('[data-voice-speed-out]');
    const error = form.querySelector('[data-voice-error]');
    const player = new Audio();
    let playing = null; // the button whose voice is playing
    let request = null;

    const reset = () => {
        if (playing) playing.textContent = 'Play';
        playing = null;
    };
    player.addEventListener('ended', reset);
    player.addEventListener('pause', reset);

    speed.addEventListener('input', () => {
        speedOut.textContent = `${Number(speed.value).toFixed(2)}×`;
    });

    form.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-voice-play]');
        if (!button) return;

        const wasThis = playing === button;
        request?.abort();
        player.pause();
        if (wasThis) return; // second click stops it

        error.hidden = true;
        playing = button;
        button.textContent = 'Making…';
        // Choosing to hear a voice also selects it.
        form.querySelector(`input[name=voice][value="${CSS.escape(button.dataset.voicePlay)}"]`).checked = true;

        request = new AbortController();
        const body = new FormData();
        body.set('voice', button.dataset.voicePlay);
        body.set('speed', speed.value);
        body.set('text', sample.value.trim() || sample.defaultValue);
        body.set('line', form.querySelector('[data-voice-line]:checked').value);

        try {
            const res = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                body,
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'audio/wav, application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (!res.ok) {
                const json = await res.json().catch(() => ({}));
                throw new Error(json.message || 'That voice could not be played.');
            }
            if (player.src) URL.revokeObjectURL(player.src);
            player.src = URL.createObjectURL(await res.blob());
            await player.play();
            playing = button; // the pause from changing src cleared it
            button.textContent = 'Stop';
        } catch (e) {
            if (e.name === 'AbortError') return;
            reset();
            error.textContent = e.message;
            error.hidden = false;
        }
    });
}

document.addEventListener('turbo:load', () => {
    document.querySelectorAll('[data-sound-form]').forEach(soundForm);
    document.querySelectorAll('[data-voice-form]').forEach(voiceForm);
});
