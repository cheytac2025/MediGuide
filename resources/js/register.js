document.addEventListener('DOMContentLoaded', () => {
    const passwordInput = document.getElementById('password');
    const rules = document.querySelectorAll('[data-mg-password-rules] [data-rule]');

    const checks = {
        length: (value) => value.length >= 8,
        upper: (value) => /[A-Z]/.test(value),
        lower: (value) => /[a-z]/.test(value),
        number: (value) => /\d/.test(value),
        special: (value) => /[^A-Za-z0-9]/.test(value),
    };

    const updateRules = () => {
        const value = passwordInput?.value ?? '';

        rules.forEach((item) => {
            const rule = item.getAttribute('data-rule');
            const passed = rule && checks[rule] ? checks[rule](value) : false;
            item.classList.toggle('is-met', passed);
        });
    };

    passwordInput?.addEventListener('input', updateRules);
    updateRules();

    document.querySelectorAll('[data-mg-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const fieldName = button.getAttribute('data-mg-toggle-password');
            const input = document.getElementById(fieldName);

            if (!input) {
                return;
            }

            const hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            button.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
            button.querySelector('[data-mg-icon="show"]')?.classList.toggle('d-none', hidden);
            button.querySelector('[data-mg-icon="hide"]')?.classList.toggle('d-none', !hidden);
        });
    });
});
