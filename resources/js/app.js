import '@hotwired/turbo';
import './phone-link';
import './admin-audio';
// Installed modules' scripts (modules/<Name>/resources/js/*.js); none in the public release.
import.meta.glob('../../modules/*/resources/js/*.js', { eager: true });

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

// <button data-copy="text"> copies the text; <button data-copy-from="id"> copies that element's text.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-copy], button[data-copy-from]');
    if (!button) {
        return;
    }
    event.preventDefault();
    const text = button.dataset.copy ?? document.getElementById(button.dataset.copyFrom)?.textContent.trim() ?? '';
    const label = button.dataset.label ??= button.textContent;
    try {
        await navigator.clipboard.writeText(text);
        button.textContent = 'Copied';
    } catch {
        button.textContent = 'Select and copy it';
    }
    setTimeout(() => { button.textContent = label; }, 1500);
});
