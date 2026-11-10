@php
    $messages = $recordTrusted ? $appointment->messages : collect();
    $approval = $appointment->status === \App\Enums\AppointmentStatus::Confirmed
        ? $appointment->latestMessage(\App\Enums\AppointmentMessageKind::ApprovalNote)
        : null;
    $rejection = $appointment->status === \App\Enums\AppointmentStatus::Rejected
        ? $appointment->latestMessage(\App\Enums\AppointmentMessageKind::RejectionReason)
        : null;
    $formatSlot = static function ($date, ?string $time): string {
        if ($date === null || $time === null || $time === '') {
            return '';
        }

        return $date->format('l, F j, Y')
            .' at '
            .\Illuminate\Support\Carbon::parse($time)->format('g:i A');
    };
@endphp

@if ($recordTrusted && ($approval || $rejection))
    <div class="mg-status-note">
        @if ($rejection)
            <p><strong>Reason shown to the patient:</strong> {{ $rejection->body }}</p>
        @endif
        @if ($approval)
            <p><strong>Note shown to the patient:</strong> {{ $approval->body }}</p>
        @endif
    </div>
@endif

@if ($recordTrusted && $messages->isNotEmpty())
    <section class="mg-appt-detail-group" aria-labelledby="appointment-updates">
        <h2 id="appointment-updates" class="mg-book-heading">Appointment updates</h2>
        <ol class="mg-message-list">
            @foreach ($messages as $message)
                <li>
                    <p class="mg-message-kind">{{ $message->kind->label() }}</p>
                    <p>{{ $message->body }}</p>
                    @if ($message->kind === \App\Enums\AppointmentMessageKind::RescheduleReason)
                        <p class="mg-appt-muted">
                            From {{ $formatSlot($message->from_date, $message->from_start_time) }}
                            to {{ $formatSlot($message->to_date, $message->to_start_time) }}
                        </p>
                    @endif
                    <p class="mg-appt-muted">
                        {{ $message->actor?->name ?? 'Hospital staff' }}
                        ·
                        {{ $message->created_at?->timezone(config('app.timezone'))->format('l, F j, Y g:i A') }}
                    </p>
                </li>
            @endforeach
        </ol>
    </section>
@endif
