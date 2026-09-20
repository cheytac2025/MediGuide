@extends('layouts.patient')

@section('title', 'Home')

@section('content')
    <section class="mg-hero">
        <div class="mg-hero-copy">
            <h1 class="mg-greeting">Good day, {{ $patient['first_name'] }}!</h1>
            <p class="mg-greeting-sub">How can we help you today?</p>
        </div>
        <blockquote class="mg-verse">
            “For I was sick and you visited me.”
            <cite>Matthew 25:36</cite>
        </blockquote>
    </section>

    <form class="mg-composer" data-mg-composer action="{{ route('patient.dashboard') }}" method="get">
        <label class="visually-hidden" for="mgComposerInput">Ask the AI Virtual Front Desk</label>
        <textarea
            id="mgComposerInput"
            class="mg-composer-input"
            name="message"
            rows="1"
            placeholder="Ask anything about your symptoms, departments, or appointment process..."
            data-mg-composer-input
        ></textarea>

        <div class="mg-composer-row">
            <div class="mg-chips">
                @foreach ($composerChips as $chip)
                    <button
                        type="button"
                        class="mg-chip"
                        data-mg-chip
                        data-mg-prompt="{{ $chip['prompt'] }}"
                    >
                        <x-patient.icon :name="$chip['icon']" />
                        {{ $chip['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="mg-composer-actions">
                <input type="file" class="d-none" data-mg-file aria-hidden="true">
                <button type="button" class="mg-attach-btn" data-mg-attach aria-label="Attach a file">
                    <x-patient.icon name="paperclip" width="20" height="20" />
                </button>
                <button type="submit" class="mg-send-btn" aria-label="Send message">
                    <x-patient.icon name="send" width="18" height="18" />
                </button>
            </div>
        </div>
    </form>

    <p class="mg-disclaimer">
        MediGuide provides guidance for patient navigation only and is not a substitute for professional medical advice.
    </p>

    <section class="mg-panel mt-3">
        <div class="mg-panel-head">
            <h2 class="mg-panel-title">Quick Actions</h2>
            <a href="#" class="mg-panel-link">See All</a>
        </div>

        <div class="mg-qa-grid">
            @foreach ($quickActions as $action)
                <x-patient.quick-action-card
                    :title="$action['title']"
                    :description="$action['description']"
                    :variant="$action['variant']"
                    :icon="$action['icon']"
                    :href="$action['href']"
                />
            @endforeach
        </div>
    </section>

    <div class="lower-grid mt-3">
        <section class="mg-panel">
            <div class="mg-panel-head">
                <h2 class="mg-panel-title">
                    <x-patient.icon name="calendar" />
                    Upcoming Appointment
                </h2>
                <a href="#" class="mg-panel-link">View All</a>
            </div>

            <x-patient.appointment-card :appointment="$appointment" />
        </section>

        <section class="mg-panel">
            <div class="mg-panel-head">
                <h2 class="mg-panel-title">
                    <x-patient.icon name="megaphone" />
                    Announcements
                </h2>
                <a href="#" class="mg-panel-link">View All</a>
            </div>

            @foreach ($announcements as $announcement)
                <x-patient.announcement-item
                    :title="$announcement['title']"
                    :date="$announcement['date']"
                    :body="$announcement['body']"
                />
            @endforeach
        </section>
    </div>
@endsection
