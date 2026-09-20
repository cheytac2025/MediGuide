@props([
    'alt' => 'Queen Mary Help of Christians Hospital',
])

<img
    src="{{ asset('images/queen-mary-logo.png') }}"
    alt="{{ $alt }}"
    {{ $attributes->class(['mg-qm-logo']) }}
>
