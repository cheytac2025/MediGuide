@extends('layouts.patient')

@section('title', 'AI Virtual Front Desk')

@section('content')
    <section class="mg-front-desk" data-mg-front-desk>
        <header class="mg-front-desk-head">
            <div>
                <h1 class="mg-greeting">AI Virtual Front Desk</h1>
                <p class="mg-greeting-sub">
                    Describe what you're experiencing and MediGuide will help guide you to the appropriate hospital service.
                </p>
            </div>
            <span class="mg-front-desk-badge">AI-Assisted Patient Navigation</span>
        </header>

        <div class="mg-chat-shell mg-panel">
            <div class="mg-chat-log" data-mg-chat-log aria-live="polite">
                <article class="mg-chat-row mg-chat-row-ai">
                    <span class="mg-chat-avatar" aria-hidden="true">
                        <x-patient.icon name="sparkle" />
                    </span>
                    <div class="mg-chat-bubble">
                        Hi, {{ $patient['first_name'] }}. I'm MediGuide, your AI-assisted virtual front desk. Tell me what you're currently experiencing, and I'll help guide you to the appropriate hospital department or specialist.
                    </div>
                </article>
            </div>

            <div class="mg-front-desk-prompts" aria-label="Example prompts">
                @foreach ($quickPrompts as $prompt)
                    <button type="button" class="mg-chip" data-mg-front-desk-prompt="{{ $prompt }}">
                        <x-patient.icon name="sparkle" />
                        {{ $prompt }}
                    </button>
                @endforeach
            </div>

            <form class="mg-composer mg-front-desk-composer" data-mg-front-desk-form>
                <label class="visually-hidden" for="mgFrontDeskInput">Describe your symptoms or health concern</label>
                <textarea
                    id="mgFrontDeskInput"
                    class="mg-composer-input"
                    name="message"
                    rows="2"
                    maxlength="{{ $maxLength }}"
                    placeholder="Describe your symptoms or health concern in your own words..."
                    data-mg-front-desk-input
                ></textarea>

                <div class="mg-composer-row">
                    <p class="mg-front-desk-count" data-mg-front-desk-count>0 / {{ $maxLength }}</p>
                    <div class="mg-composer-actions">
                        <button type="submit" class="mg-send-btn" data-mg-front-desk-send aria-label="Send message">
                            <x-patient.icon name="send" width="18" height="18" />
                        </button>
                    </div>
                </div>
            </form>

            <p class="mg-disclaimer mg-front-desk-disclaimer">
                MediGuide provides patient navigation assistance only. It does not provide a medical diagnosis, prescribe treatment, or replace consultation with a licensed healthcare professional.
            </p>
        </div>
    </section>

    <script type="application/json" data-mg-front-desk-config>
        @json($frontDeskConfig)
    </script>

    <template data-mg-tpl="patient">
        <article class="mg-chat-row mg-chat-row-patient">
            <div class="mg-chat-bubble" data-mg-text></div>
            <span class="mg-chat-avatar mg-chat-avatar-patient" data-mg-initials></span>
        </article>
    </template>

    <template data-mg-tpl="processing">
        <article class="mg-chat-row mg-chat-row-ai" data-mg-processing>
            <span class="mg-chat-avatar" aria-hidden="true">
                <x-patient.icon name="sparkle" />
            </span>
            <div class="mg-chat-bubble mg-chat-processing">
                <p class="mg-chat-processing-label"></p>
                <ol class="mg-chat-steps"></ol>
            </div>
        </article>
    </template>

    <template data-mg-tpl="recommendation">
        <article class="mg-chat-row mg-chat-row-ai">
            <span class="mg-chat-avatar" aria-hidden="true">
                <x-patient.icon name="sparkle" />
            </span>
            <div class="mg-recommend-card">
                <p class="mg-recommend-kicker">
                    <span data-mg-source></span>
                    <span class="mg-recommend-tag">Development data</span>
                </p>
                <h2 class="mg-recommend-title">Recommended Service</h2>
                <p class="mg-recommend-department">
                    <x-patient.icon name="building" />
                    <span data-mg-department></span>
                </p>
                <p class="mg-recommend-specialist" data-mg-specialist></p>
                <p class="mg-recommend-summary" data-mg-summary></p>
                <button type="button" class="mg-recommend-btn" disabled title="Doctor directory is not available yet">
                    View Available Doctors
                </button>
                <button type="button" class="mg-recommend-link" data-mg-preview-fallback>
                    Preview staff-assistance fallback
                </button>
            </div>
        </article>
    </template>

    <template data-mg-tpl="fallback">
        <article class="mg-chat-row mg-chat-row-ai">
            <span class="mg-chat-avatar" aria-hidden="true">
                <x-patient.icon name="exclamation-triangle" />
            </span>
            <div class="mg-fallback-card">
                <p class="mg-recommend-tag">Development data</p>
                <h2 class="mg-recommend-title">Additional assistance needed</h2>
                <p>We couldn't confidently determine the appropriate department from the information provided.</p>
                <p>Please contact or approach authorized hospital personnel for assistance.</p>
                <button type="button" class="mg-recommend-btn" disabled title="Hospital staff contact is not available yet">
                    Ask Hospital Staff
                </button>
            </div>
        </article>
    </template>
@endsection

@push('scripts')
    <script src="{{ asset('js/ai-front-desk.js') }}"></script>
@endpush
