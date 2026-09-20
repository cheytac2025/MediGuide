@props([
    'title',
    'date',
    'body',
    'href' => '#',
])

<a href="{{ $href }}" class="mg-announcement">
    <x-patient.icon name="clock" class="mg-announcement-icon" />
    <span class="mg-announcement-copy">
        <p class="mg-announcement-title">
            {{ $title }}
            <span class="mg-announcement-date">{{ $date }}</span>
        </p>
        <p class="mg-announcement-body">{{ $body }}</p>
    </span>
    <x-patient.icon name="chevron-right" class="mg-announcement-chevron" width="16" height="16" />
</a>
