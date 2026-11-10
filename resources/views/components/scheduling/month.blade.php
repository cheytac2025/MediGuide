@php
    $weeks = $calendar['weeks'] ?? [];
@endphp

<div class="mg-cal">
    <div class="mg-cal-head">
        @if (! empty($calendar['prevUrl']))
            <a class="mg-cal-nav" href="{{ $calendar['prevUrl'] }}" aria-label="Previous month">
                <x-patient.icon name="chevron-right" class="mg-cal-prev" width="18" height="18" />
            </a>
        @else
            <button type="button" class="mg-cal-nav" aria-label="Previous month" disabled>
                <x-patient.icon name="chevron-right" class="mg-cal-prev" width="18" height="18" />
            </button>
        @endif

        <h3 class="mg-cal-title">{{ $calendar['label'] }}</h3>

        @if (! empty($calendar['nextUrl']))
            <a class="mg-cal-nav" href="{{ $calendar['nextUrl'] }}" aria-label="Next month">
                <x-patient.icon name="chevron-right" width="18" height="18" />
            </a>
        @else
            <button type="button" class="mg-cal-nav" aria-label="Next month" disabled>
                <x-patient.icon name="chevron-right" width="18" height="18" />
            </button>
        @endif
    </div>

    <table class="mg-cal-grid">
        <thead>
            <tr>
                @foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday)
                    <th scope="col" abbr="{{ $weekday }}">{{ substr($weekday, 0, 3) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($weeks as $week)
                <tr>
                    @foreach ($week as $day)
                        <td>
                            @if ($day === null)
                            @elseif (! empty($day['href']))
                                <a
                                    class="mg-cal-day {{ $day['selected'] ? 'is-selected' : '' }} {{ $day['today'] ? 'is-today' : '' }} {{ $day['marker'] ?? '' }}"
                                    href="{{ $day['href'] }}"
                                    aria-label="{{ $day['label'] }}"
                                    @if ($day['selected']) aria-current="date" @endif
                                >{{ $day['day'] }}</a>
                            @else
                                <button
                                    type="button"
                                    class="mg-cal-day {{ $day['today'] ? 'is-today' : '' }} {{ $day['marker'] ?? '' }}"
                                    aria-label="{{ $day['label'] }}, unavailable"
                                    disabled
                                >{{ $day['day'] }}</button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
