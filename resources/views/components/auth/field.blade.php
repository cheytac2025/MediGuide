@props([
    'name',
    'label',
    'type' => 'text',
    'required' => false,
    'autocomplete' => null,
    'placeholder' => null,
    'value' => null,
    'help' => null,
    'max' => null,
])

@php
    $id = $attributes->get('id', $name);
    $hasError = $errors->has($name);
    $inputValue = $type === 'password' ? '' : old($name, $value);
@endphp

<div class="mg-field">
    <div class="mg-field-head">
        <label for="{{ $id }}" class="mg-label">
            {{ $label }}
            @if ($required)
                <span class="mg-required" aria-hidden="true">*</span>
                <span class="visually-hidden">required</span>
            @endif
        </label>

        {{ $labelAction ?? '' }}
    </div>

    <div class="mg-input-wrap">
        {{ $prefix ?? '' }}

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            class="mg-input {{ $hasError ? 'is-invalid' : '' }}"
            value="{{ $inputValue }}"
            @if ($required) required @endif
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($max) max="{{ $max }}" @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except('id') }}
        >

        {{ $addon ?? '' }}
    </div>

    @if ($help)
        <p class="mg-field-help">{{ $help }}</p>
    @endif

    @error($name)
        <p id="{{ $id }}-error" class="mg-field-error" role="alert">{{ $message }}</p>
    @enderror
</div>
