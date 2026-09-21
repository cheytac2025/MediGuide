(() => {
    const root = document.querySelector('[data-mg-front-desk]');

    if (!root) {
        return;
    }

    const configEl = document.querySelector('[data-mg-front-desk-config]');
    const config = configEl ? JSON.parse(configEl.textContent) : {};
    const log = root.querySelector('[data-mg-chat-log]');
    const form = root.querySelector('[data-mg-front-desk-form]');
    const input = root.querySelector('[data-mg-front-desk-input]');
    const count = root.querySelector('[data-mg-front-desk-count]');
    const sendBtn = root.querySelector('[data-mg-front-desk-send]');
    const maxLength = config.maxLength || 1000;
    const templates = {
        patient: document.querySelector('[data-mg-tpl="patient"]'),
        processing: document.querySelector('[data-mg-tpl="processing"]'),
        recommendation: document.querySelector('[data-mg-tpl="recommendation"]'),
        fallback: document.querySelector('[data-mg-tpl="fallback"]'),
    };

    let busy = false;

    const updateCount = () => {
        if (count) {
            count.textContent = `${input?.value.length || 0} / ${maxLength}`;
        }
    };

    const scrollToEnd = () => {
        if (log) {
            log.scrollTop = log.scrollHeight;
        }
    };

    const cloneTemplate = (name) => templates[name]?.content.firstElementChild.cloneNode(true);

    const appendPatientMessage = (text) => {
        const row = cloneTemplate('patient');

        if (!row) {
            return;
        }

        row.querySelector('[data-mg-text]').textContent = text;
        row.querySelector('[data-mg-initials]').textContent = config.patientInitials || '';
        log.appendChild(row);
    };

    const appendProcessing = () => {
        const row = cloneTemplate('processing');

        if (!row) {
            return null;
        }

        row.querySelector('.mg-chat-processing-label').textContent = config.processingLabel;
        const list = row.querySelector('.mg-chat-steps');
        (config.processingSteps || []).forEach((step, index) => {
            const item = document.createElement('li');
            item.textContent = step;
            if (index === 0) {
                item.classList.add('is-active');
            }
            list.appendChild(item);
        });
        log.appendChild(row);
        return row;
    };

    const advanceSteps = (row) => {
        const items = [...row.querySelectorAll('.mg-chat-steps li')];
        let index = 0;

        return window.setInterval(() => {
            index = Math.min(index + 1, items.length - 1);
            items.forEach((item, itemIndex) => {
                item.classList.toggle('is-active', itemIndex === index);
                item.classList.toggle('is-done', itemIndex < index);
            });
        }, 520);
    };

    const appendRecommendation = () => {
        const row = cloneTemplate('recommendation');
        const mock = config.mockRecommendation || {};

        if (!row) {
            return;
        }

        row.querySelector('[data-mg-source]').textContent = mock.source || 'DEVELOPMENT DATA';
        row.querySelector('[data-mg-department]').textContent = mock.department || 'Development Department';
        row.querySelector('[data-mg-specialist]').textContent = mock.specialist || 'Test Specialist';
        row.querySelector('[data-mg-summary]').textContent = mock.summary || '';
        log.appendChild(row);
    };

    const appendFallback = () => {
        const row = cloneTemplate('fallback');

        if (!row || log.querySelector('.mg-fallback-card')) {
            return;
        }

        log.appendChild(row);
        scrollToEnd();
    };

    /**
     * Placeholder for the future NLP/API request.
     * Replace mockGuidance() with a fetch() to the Laravel endpoint later.
     */
    const requestGuidance = (message) => mockGuidance(message);

    const mockGuidance = () => new Promise((resolve) => {
        window.setTimeout(() => {
            resolve({ type: 'recommendation', development: true });
        }, 1600);
    });

    const submitMessage = async (rawMessage) => {
        const message = rawMessage.trim();

        if (!message || busy) {
            return;
        }

        busy = true;
        sendBtn.disabled = true;
        appendPatientMessage(message);
        input.value = '';
        input.style.height = 'auto';
        updateCount();
        scrollToEnd();

        const processing = appendProcessing();
        const stepper = processing ? advanceSteps(processing) : null;
        scrollToEnd();

        try {
            await requestGuidance(message);
            processing?.remove();
            appendRecommendation();
        } finally {
            window.clearInterval(stepper);
            busy = false;
            sendBtn.disabled = false;
            input.focus();
            scrollToEnd();
        }
    };

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        submitMessage(input?.value || '');
    });

    input?.addEventListener('input', () => {
        updateCount();
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
    });

    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            submitMessage(input.value);
        }
    });

    root.querySelectorAll('[data-mg-front-desk-prompt]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!input) {
                return;
            }

            input.value = button.getAttribute('data-mg-front-desk-prompt') || '';
            updateCount();
            input.focus();
        });
    });

    log?.addEventListener('click', (event) => {
        if (event.target.closest('[data-mg-preview-fallback]')) {
            appendFallback();
        }
    });

    updateCount();
})();
