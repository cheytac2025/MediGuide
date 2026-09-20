document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-mg-composer]');
    const input = document.querySelector('[data-mg-composer-input]');
    const chips = document.querySelectorAll('[data-mg-chip]');
    const attachBtn = document.querySelector('[data-mg-attach]');
    const fileInput = document.querySelector('[data-mg-file]');

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            if (!input) {
                return;
            }

            input.value = chip.getAttribute('data-mg-prompt') || chip.textContent.trim();
            input.focus();
        });
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        input?.blur();
    });

    attachBtn?.addEventListener('click', () => {
        fileInput?.click();
    });

    input?.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 120)}px`;
    });
});
