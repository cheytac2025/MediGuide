@props([
    'title',
    'description',
    'variant' => 'blue',
    'icon' => 'chat',
    'href' => '#',
])

<a href="{{ $href }}" class="mg-qa-card is-{{ $variant }}">
    <span class="mg-qa-icon">
        <x-patient.icon :name="$icon" />
    </span>
    <h3 class="mg-qa-title">{{ $title }}</h3>
    <p class="mg-qa-desc">{{ $description }}</p>
    <span class="mg-qa-go" aria-hidden="true">
        <x-patient.icon name="arrow-right" width="14" height="14" />
    </span>
</a>
