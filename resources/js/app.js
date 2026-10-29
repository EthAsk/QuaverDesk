import '@hotwired/turbo';
import './phone-link';
import './admin-audio';

// Small progressive enhancements, kept out of inline attributes so the
// Content-Security-Policy can forbid inline scripts.
//
// Turbo swaps pages in place, so the phone docked in the corner keeps running
// (and keeps any call going) while people move around the app. Code that sets
// up a page runs on "turbo:load", which also fires for the first page.

document.addEventListener('turbo:load', () => {
    const dock = document.getElementById('phone');
    if (dock && !dock.dataset.booted) {
        import('./phone/runtime').then(({ boot }) => boot(dock));
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target.closest('form[data-confirm]');
    if (form && !window.confirm(form.dataset.confirm)) {
        event.preventDefault();
    }
});

document.addEventListener('change', (event) => {
    if (event.target.matches('select[data-autosubmit]')) {
        event.target.form.requestSubmit();
    }
});
