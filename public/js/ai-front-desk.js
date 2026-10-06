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
    const clearBtn = root.querySelector('[data-mg-clear-conversation]');
    const maxLength = config.maxLength || 1000;
    const fallbackMessage = config.fallbackMessage || "I'm unable to process your concern right now. Please try again or ask hospital staff for assistance.";
    const templates = {
        patient: document.querySelector('[data-mg-tpl="patient"]'),
        processing: document.querySelector('[data-mg-tpl="processing"]'),
        assistant: document.querySelector('[data-mg-tpl="assistant"]'),
        recommendation: document.querySelector('[data-mg-tpl="recommendation"]'),
        safety: document.querySelector('[data-mg-tpl="safety"]'),
        doctors: document.querySelector('[data-mg-tpl="doctors"]'),
        doctorItem: document.querySelector('[data-mg-tpl="doctor-item"]'),
        fallback: document.querySelector('[data-mg-tpl="fallback"]'),
    };

    let busy = false;
    let loadingDoctors = false;

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

    const setBusy = (next) => {
        busy = next;

        if (sendBtn) {
            sendBtn.disabled = next;
        }

        if (input) {
            input.disabled = next;
        }

        if (clearBtn) {
            clearBtn.disabled = next;
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

    const appendAssistantMessage = (text) => {
        const row = cloneTemplate('assistant');

        if (!row) {
            return;
        }

        row.querySelector('[data-mg-text]').textContent = text;
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

    const clinicIdFromPayload = (payload) => {
        const id = Number(payload?.clinic?.id);

        return Number.isInteger(id) && id > 0 ? id : null;
    };

    const appendRecommendation = (payload) => {
        const clinicId = clinicIdFromPayload(payload);
        const doctorsUrl = typeof payload?.doctors_url === 'string' ? payload.doctors_url : '';

        if (!clinicId || doctorsUrl === '' || typeof payload?.clinic?.name !== 'string') {
            appendAssistantMessage(fallbackMessage);
            appendFallback();
            return;
        }

        const row = cloneTemplate('recommendation');

        if (!row) {
            return;
        }

        const isDevelopment = payload.data_source === 'development';
        row.querySelector('[data-mg-source]').textContent = isDevelopment ? 'DEVELOPMENT DATA' : '';
        const developmentTag = row.querySelector('[data-mg-development-tag]');

        if (developmentTag) {
            developmentTag.hidden = !isDevelopment;
        }

        row.querySelector('[data-mg-clinic]').textContent = payload.clinic.name;
        row.querySelector('[data-mg-department]').textContent = payload.clinic.department || '';
        row.querySelector('[data-mg-summary]').textContent = payload.reason || '';

        const viewBtn = row.querySelector('[data-mg-view-doctors]');

        if (viewBtn) {
            viewBtn.disabled = false;
            viewBtn.dataset.clinicId = String(clinicId);
            viewBtn.dataset.doctorsUrl = doctorsUrl;
            viewBtn.removeAttribute('title');
        }

        log.appendChild(row);
    };

    const appendSafety = (text) => {
        const row = cloneTemplate('safety');

        if (!row) {
            appendAssistantMessage(text);
            return;
        }

        row.querySelector('[data-mg-text]').textContent = text;
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

    const renderGuidance = (payload) => {
        if (payload?.type === 'recommendation') {
            appendRecommendation(payload);
            return;
        }

        if (payload?.type === 'clarification' && typeof payload.question === 'string') {
            appendAssistantMessage(payload.question);
            return;
        }

        if (payload?.type === 'safety' && typeof payload.message === 'string') {
            appendSafety(payload.message);
            return;
        }

        appendAssistantMessage(typeof payload?.message === 'string' ? payload.message : fallbackMessage);
        appendFallback();
    };

    const appendDoctors = (payload) => {
        const row = cloneTemplate('doctors');

        if (!row) {
            return;
        }

        row.querySelector('[data-mg-doctors-clinic]').textContent = payload.clinic?.name || 'Available Doctors';

        const messageEl = row.querySelector('[data-mg-doctors-message]');
        const listEl = row.querySelector('[data-mg-doctors-list]');
        const doctors = Array.isArray(payload.doctors) ? payload.doctors : [];

        if (payload.message && messageEl) {
            messageEl.textContent = payload.message;
            messageEl.hidden = false;
        }

        doctors.forEach((doctor) => {
            const item = templates.doctorItem?.content.firstElementChild.cloneNode(true);

            if (!item) {
                return;
            }

            item.querySelector('[data-mg-doctor-name]').textContent = doctor.display_name || '';
            item.querySelector('[data-mg-doctor-specialization]').textContent = doctor.specialization || '';
            item.querySelector('[data-mg-doctor-clinic]').textContent = doctor.clinic || payload.clinic?.name || '';
            item.querySelector('[data-mg-doctor-availability]').textContent = doctor.availability || '';

            const bookBtn = item.querySelector('[data-mg-book-appointment]');

            if (bookBtn) {
                if (doctor.bookable) {
                    bookBtn.disabled = false;
                    bookBtn.dataset.clinicId = String(payload.clinic?.id || '');
                    bookBtn.dataset.doctorId = String(doctor.id);
                    bookBtn.dataset.bookUrl = payload.book_appointment_url || '';
                    bookBtn.dataset.intentUrl = payload.booking_intent_url || '';
                    bookBtn.dataset.authenticatedPatient = payload.is_authenticated_patient ? '1' : '0';
                } else {
                    bookBtn.disabled = true;
                    bookBtn.title = 'No available schedule';
                }
            }

            listEl.appendChild(item);
        });

        log.appendChild(row);
        scrollToEnd();
    };

    const loadDoctors = async (url, button) => {
        if (!url || loadingDoctors) {
            return;
        }

        loadingDoctors = true;

        if (button) {
            button.disabled = true;
            button.textContent = 'Loading doctors...';
        }

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                redirect: 'manual',
            });

            if (response.type === 'opaqueredirect' || response.status === 0 || (response.status >= 300 && response.status < 400)) {
                window.location.href = response.url || '/ai-disclaimer';
                return;
            }

            const contentType = response.headers.get('content-type') || '';

            if (!response.ok || !contentType.includes('application/json')) {
                const errorPayload = contentType.includes('application/json')
                    ? await response.json().catch(() => ({}))
                    : {};
                appendDoctors({
                    clinic: null,
                    doctors: [],
                    message: errorPayload.message || 'No available doctors for this clinic at the moment.',
                });
                return;
            }

            const payload = await response.json();
            appendDoctors(payload);
        } catch (error) {
            appendDoctors({
                clinic: null,
                doctors: [],
                message: 'No available doctors for this clinic at the moment.',
            });
        } finally {
            loadingDoctors = false;

            if (button) {
                button.disabled = false;
                button.textContent = 'View Available Doctors';
            }
        }
    };

    const startBooking = (button) => {
        const clinicId = button.dataset.clinicId;
        const doctorId = button.dataset.doctorId;
        const isPatient = button.dataset.authenticatedPatient === '1';
        const bookUrl = button.dataset.bookUrl;
        const intentUrl = button.dataset.intentUrl;

        if (!clinicId || !doctorId) {
            return;
        }

        if (isPatient && bookUrl) {
            const url = new URL(bookUrl, window.location.origin);
            url.searchParams.set('clinic_id', clinicId);
            url.searchParams.set('doctor_id', doctorId);
            window.location.href = url.toString();
            return;
        }

        if (!intentUrl) {
            return;
        }

        const formEl = document.createElement('form');
        formEl.method = 'POST';
        formEl.action = intentUrl;
        formEl.style.display = 'none';

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = config.csrfToken || '';
        formEl.appendChild(token);

        const clinicInput = document.createElement('input');
        clinicInput.type = 'hidden';
        clinicInput.name = 'clinic_id';
        clinicInput.value = clinicId;
        formEl.appendChild(clinicInput);

        const doctorInput = document.createElement('input');
        doctorInput.type = 'hidden';
        doctorInput.name = 'doctor_id';
        doctorInput.value = doctorId;
        formEl.appendChild(doctorInput);

        document.body.appendChild(formEl);
        formEl.submit();
    };

    const requestGuidance = async (message) => {
        const response = await fetch(config.guidanceUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ message }),
        });

        const contentType = response.headers.get('content-type') || '';
        const payload = contentType.includes('application/json')
            ? await response.json().catch(() => null)
            : null;

        if (response.status === 429) {
            return {
                type: 'uncertain',
                message: payload?.message || config.rateLimitMessage || fallbackMessage,
            };
        }

        if (response.status === 422) {
            const validationMessage = payload?.errors?.message?.[0] || payload?.message;

            return {
                type: 'uncertain',
                message: typeof validationMessage === 'string' ? validationMessage : fallbackMessage,
            };
        }

        if (!response.ok || !payload || typeof payload.type !== 'string') {
            throw new Error('Guidance request failed.');
        }

        return payload;
    };

    const submitMessage = async (rawMessage) => {
        const message = rawMessage.trim();

        if (!message || busy) {
            return;
        }

        setBusy(true);
        appendPatientMessage(message);
        input.value = '';
        input.style.height = 'auto';
        updateCount();
        scrollToEnd();

        const processing = appendProcessing();
        const stepper = processing ? advanceSteps(processing) : null;
        scrollToEnd();

        try {
            const payload = await requestGuidance(message);
            processing?.remove();
            renderGuidance(payload);
        } catch (error) {
            processing?.remove();
            appendAssistantMessage(fallbackMessage);
            appendFallback();
        } finally {
            window.clearInterval(stepper);
            setBusy(false);
            input.focus();
            scrollToEnd();
        }
    };

    const clearConversation = async () => {
        if (busy || !config.clearConversationUrl) {
            return;
        }

        setBusy(true);

        try {
            const response = await fetch(config.clearConversationUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': config.csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            log?.querySelectorAll('.mg-chat-row').forEach((row) => {
                if (!row.hasAttribute('data-mg-greeting')) {
                    row.remove();
                }
            });
        } catch (error) {
            appendAssistantMessage(fallbackMessage);
        } finally {
            setBusy(false);
            input?.focus();
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
            if (!input || busy) {
                return;
            }

            input.value = button.getAttribute('data-mg-front-desk-prompt') || '';
            updateCount();
            input.focus();
        });
    });

    clearBtn?.addEventListener('click', () => {
        clearConversation();
    });

    log?.addEventListener('click', (event) => {
        const viewDoctors = event.target.closest('[data-mg-view-doctors]');

        if (viewDoctors && !viewDoctors.disabled) {
            loadDoctors(viewDoctors.dataset.doctorsUrl, viewDoctors);
            return;
        }

        const bookAppointment = event.target.closest('[data-mg-book-appointment]');

        if (bookAppointment && !bookAppointment.disabled) {
            startBooking(bookAppointment);
        }
    });

    updateCount();
})();
