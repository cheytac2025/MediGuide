@props([
    'appointment',
])

<article class="mg-appt">
    <div class="mg-date" aria-label="{{ $appointment['month'] }} {{ $appointment['day'] }}, {{ $appointment['year'] }}">
        <span class="mg-date-month">{{ $appointment['month'] }}</span>
        <span class="mg-date-day">{{ $appointment['day'] }}</span>
        <span class="mg-date-year">{{ $appointment['year'] }}</span>
    </div>

    <div class="mg-appt-body">
        <div class="mg-appt-top">
            <h3 class="mg-appt-title">{{ $appointment['title'] }}</h3>
            <span class="mg-status">{{ $appointment['status'] }}</span>
        </div>

        <ul class="mg-appt-meta">
            <li>
                <x-patient.icon name="user" />
                <span>{{ $appointment['doctor'] }}</span>
            </li>
            <li>
                <x-patient.icon name="clock" />
                <span>{{ $appointment['time'] }}</span>
            </li>
            <li>
                <x-patient.icon name="pin" />
                <span>{{ $appointment['location'] }}</span>
            </li>
        </ul>
    </div>
</article>
