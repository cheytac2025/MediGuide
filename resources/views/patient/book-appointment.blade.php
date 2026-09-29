@extends('layouts.patient')

@section('title', 'Book Appointment')

@section('content')
    @php
        $progress = [
            1 => 'Clinic',
            2 => 'Doctor',
            3 => 'Date',
            4 => 'Time',
            5 => 'Details',
            6 => 'Review',
        ];

        $stepQuery = [
            1 => [],
            2 => ['clinic_id' => $clinic?->id],
            3 => ['clinic_id' => $clinic?->id, 'doctor_id' => $doctor?->id],
            4 => [
                'clinic_id' => $clinic?->id,
                'doctor_id' => $doctor?->id,
                'appointment_date' => $date?->toDateString(),
            ],
            5 => [
                'clinic_id' => $clinic?->id,
                'doctor_id' => $doctor?->id,
                'appointment_date' => $date?->toDateString(),
                'start_time' => $slot['start'] ?? null,
            ],
        ];
    @endphp

    <div class="mg-book">
        <section class="mg-panel">
            <div class="mg-panel-head">
                <h1 class="mg-panel-title">Book Appointment</h1>
            </div>

            <ol class="mg-progress" aria-label="Booking progress">
                @foreach ($progress as $number => $label)
                    @php
                        $isCurrent = $step === $number;
                        $isComplete = $step > $number && isset($stepQuery[$number]);
                    @endphp
                    <li class="mg-progress-item {{ $isCurrent ? 'is-current' : '' }} {{ $isComplete ? 'is-complete' : '' }}">
                        @if ($isComplete)
                            <a href="{{ route('patient.book-appointment', array_filter($stepQuery[$number])) }}">
                                <span>{{ $number }}</span>
                                {{ $label }}
                            </a>
                        @else
                            <span>
                                <span>{{ $number }}</span>
                                {{ $label }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($error)
                <div class="mg-book-alert" role="alert">{{ $error }}</div>
            @endif

            @if ($errors->any())
                <div class="mg-book-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            @if ($clinic)
                <p class="mg-book-context">
                    {{ $clinic->name }}
                    @if ($doctor)
                        · {{ $doctor->display_name }}
                    @endif
                    @if ($date)
                        · {{ $date->format('D, M j, Y') }}
                    @endif
                    @if (is_array($slot))
                        · {{ $slot['label'] }}
                    @endif
                </p>
            @endif

            @if ($step === 1)
                <h2 class="mg-book-heading">Select a clinic</h2>
                @if ($clinics->isEmpty())
                    <p class="mg-book-empty">No clinics are available for booking right now.</p>
                @else
                    <div class="mg-choice-list">
                        @foreach ($clinics as $choice)
                            <a class="mg-choice" href="{{ route('patient.book-appointment', ['clinic_id' => $choice->id]) }}">
                                <strong>{{ $choice->name }}</strong>
                                @if ($choice->description)
                                    <span>{{ $choice->description }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($step === 2 && $clinic)
                <h2 class="mg-book-heading">Select a doctor</h2>
                @if ($doctors->isEmpty())
                    <p class="mg-book-empty">No doctors are available for this clinic.</p>
                @else
                    <div class="mg-choice-list">
                        @foreach ($doctors as $choice)
                            <a class="mg-choice" href="{{ route('patient.book-appointment', ['clinic_id' => $clinic->id, 'doctor_id' => $choice->id]) }}">
                                <strong>{{ $choice->display_name }}</strong>
                                @if ($choice->specialization)
                                    <span>{{ $choice->specialization }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($step === 3 && $clinic && $doctor)
                <h2 class="mg-book-heading">Select a date</h2>
                @if ($dates === [])
                    <p class="mg-book-empty">No upcoming dates are available for this doctor.</p>
                @else
                    <div class="mg-choice-list">
                        @foreach ($dates as $choice)
                            <a
                                class="mg-choice"
                                href="{{ route('patient.book-appointment', ['clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'appointment_date' => $choice->toDateString()]) }}"
                            >
                                <strong>{{ $choice->format('l, F j, Y') }}</strong>
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($step === 4 && $clinic && $doctor && $date)
                <h2 class="mg-book-heading">Select a time</h2>
                @if ($slots === [])
                    <p class="mg-book-empty">No times are available on this date.</p>
                @else
                    <div class="mg-slot-grid">
                        @foreach ($slots as $choice)
                            <a
                                class="mg-slot"
                                href="{{ route('patient.book-appointment', [
                                    'clinic_id' => $clinic->id,
                                    'doctor_id' => $doctor->id,
                                    'appointment_date' => $date->toDateString(),
                                    'start_time' => $choice['start'],
                                ]) }}"
                            >{{ $choice['label'] }}</a>
                        @endforeach
                    </div>
                @endif
            @endif

            @if ($step === 5 && $clinic && $doctor && $date && is_array($slot))
                <h2 class="mg-book-heading">Your details</h2>
                <dl class="mg-review-list">
                    <div>
                        <dt>Patient name</dt>
                        <dd>{{ $profile['name'] }}</dd>
                    </div>
                    <div>
                        <dt>Email</dt>
                        <dd>{{ $profile['email'] }}</dd>
                    </div>
                    <div>
                        <dt>Contact information</dt>
                        <dd>{{ $profile['contact'] ?: 'Not provided' }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('patient.book-appointment.review') }}" class="mg-book-form">
                    @csrf
                    <input type="hidden" name="clinic_id" value="{{ $clinic->id }}">
                    <input type="hidden" name="doctor_id" value="{{ $doctor->id }}">
                    <input type="hidden" name="doctor_schedule_id" value="{{ $slot['doctor_schedule_id'] }}">
                    <input type="hidden" name="appointment_date" value="{{ $date->toDateString() }}">
                    <input type="hidden" name="start_time" value="{{ $slot['start'] }}">

                    <label class="mg-book-label" for="patientConcern">Patient concern <span>(optional)</span></label>
                    <textarea
                        id="patientConcern"
                        name="patient_concern"
                        class="mg-book-textarea"
                        rows="4"
                        maxlength="1000"
                        placeholder="Briefly describe your concern..."
                    >{{ old('patient_concern') }}</textarea>

                    <button type="submit" class="mg-auth-submit">Continue</button>
                </form>
            @endif

            @if ($step === 6 && is_array($review))
                <h2 class="mg-book-heading">Review your appointment</h2>
                <dl class="mg-review-list">
                    <div>
                        <dt>Clinic</dt>
                        <dd>{{ $review['clinic'] }}</dd>
                    </div>
                    <div>
                        <dt>Doctor</dt>
                        <dd>{{ $review['doctor'] }}</dd>
                    </div>
                    <div>
                        <dt>Specialization</dt>
                        <dd>{{ $review['specialization'] ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt>Date</dt>
                        <dd>{{ $review['date'] }}</dd>
                    </div>
                    <div>
                        <dt>Time</dt>
                        <dd>{{ $review['time'] }}</dd>
                    </div>
                    <div>
                        <dt>Patient concern</dt>
                        <dd>{{ $review['concern'] ?: 'None' }}</dd>
                    </div>
                    <div>
                        <dt>Appointment status</dt>
                        <dd>Pending</dd>
                    </div>
                </dl>

                <p class="mg-book-note">
                    Your appointment request will be submitted as pending and may require hospital confirmation.
                </p>

                <form method="POST" action="{{ route('patient.book-appointment.store') }}" class="mg-book-form" data-mg-booking-confirm>
                    @csrf
                    <input type="hidden" name="clinic_id" value="{{ $review['clinic_id'] }}">
                    <input type="hidden" name="doctor_id" value="{{ $review['doctor_id'] }}">
                    <input type="hidden" name="doctor_schedule_id" value="{{ $review['doctor_schedule_id'] }}">
                    <input type="hidden" name="appointment_date" value="{{ $review['appointment_date'] }}">
                    <input type="hidden" name="start_time" value="{{ $review['start_time'] }}">
                    <input type="hidden" name="patient_concern" value="{{ $review['concern'] }}">

                    <button type="submit" class="mg-auth-submit" data-mg-confirm-button>Confirm Appointment</button>
                </form>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.querySelector('[data-mg-booking-confirm]')?.addEventListener('submit', (event) => {
            const button = event.currentTarget.querySelector('[data-mg-confirm-button]');

            if (!button || button.disabled) {
                event.preventDefault();
                return;
            }

            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = 'Submitting...';
        });
    </script>
@endpush
