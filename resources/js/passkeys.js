// Passkeys (Plus and Pro): add one on the Security page, sign in with one on
// the sign-in page. The WebAuthn work is done by Laravel's passkeys client
// against Fortify's passkey routes.

import { Passkeys, PasskeyExistsError, UserCancelledError } from '@laravel/passkeys';

function show(el, message) {
    el.textContent = message;
    el.hidden = !message;
}

const friendly = (e) => {
    if (e instanceof UserCancelledError) return '';
    if (e instanceof PasskeyExistsError) return 'This device already has a passkey for your account.';
    return e?.message || 'Something went wrong. Please try again.';
};

// ---- Security page: add a passkey ------------------------------------------

const addForm = document.getElementById('passkey-add');
if (addForm) {
    const error = document.getElementById('passkey-error');
    if (!Passkeys.isSupported()) {
        addForm.hidden = true;
        document.getElementById('passkey-unsupported').hidden = false;
    }

    addForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        show(error, '');
        const button = addForm.querySelector('button');
        button.disabled = true;
        try {
            await Passkeys.register({ name: document.getElementById('passkey-name').value.trim() });
            window.location.reload();
        } catch (e) {
            show(error, friendly(e));
        } finally {
            button.disabled = false;
        }
    });
}

// ---- Sign-in page ---------------------------------------------------------

const login = document.getElementById('passkey-login');
if (login && Passkeys.isSupported()) {
    const error = document.getElementById('passkey-login-error');
    const button = document.getElementById('passkey-login-btn');
    const remember = () => document.querySelector('input[name="remember"]')?.checked ?? false;
    const go = (response) => { window.location.href = response?.redirect || '/dashboard'; };

    login.hidden = false;

    button.addEventListener('click', async () => {
        show(error, '');
        button.disabled = true;
        try {
            Passkeys.cancel(); // stop the autofill request so the full dialog can open
            go(await Passkeys.verify({ remember }));
        } catch (e) {
            show(error, friendly(e));
        } finally {
            button.disabled = false;
        }
    });

    // Offer saved passkeys in the email box's suggestions, where the browser supports it.
    Passkeys.isAutofillSupported().then((ok) => {
        if (!ok) return;
        Passkeys.autofill({ remember }).then((r) => r && go(r)).catch((e) => show(error, friendly(e)));
    });
}
