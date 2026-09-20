@extends('layouts.guest')

@section('title', 'Create Your Account')

@section('content')
    <div class="mg-auth">
        <aside class="mg-auth-brand" aria-label="Queen Mary Help of Christians Hospital">
            <div class="mg-auth-brand-inner">
                <x-queen-mary-logo class="mg-auth-logo" />
                <p class="mg-hospital-name">
                    Queen Mary
                    <span>Help of Christians Hospital</span>
                </p>
                <p class="mg-hospital-tagline mg-auth-tagline">Compassion. Care. For a Healthier Tomorrow.</p>
                <img
                    src="{{ asset('images/virgin-mary.png') }}"
                    alt=""
                    class="mg-auth-mary"
                >
            </div>
        </aside>

        <main class="mg-auth-panel">
            <div class="mg-auth-card">
                <p class="mg-wordmark">MediGuide</p>
                <p class="mg-wordmark-sub">Your Health. Our Guidance.</p>

                <h1 class="mg-auth-title">Create Your Account</h1>
                <p class="mg-auth-lead">
                    Create your MediGuide patient account to access appointment and patient navigation services.
                </p>

                <form method="POST" action="{{ route('register.store') }}" class="mg-auth-form" novalidate data-mg-register>
                    @csrf

                    <div class="mg-auth-grid">
                        <x-auth.field
                            name="first_name"
                            label="First Name"
                            :required="true"
                            autocomplete="given-name"
                            placeholder="Juan"
                        />

                        <x-auth.field
                            name="middle_name"
                            label="Middle Name"
                            autocomplete="additional-name"
                            placeholder="Optional"
                        />

                        <x-auth.field
                            name="last_name"
                            label="Last Name"
                            :required="true"
                            autocomplete="family-name"
                            placeholder="Dela Cruz"
                        />

                        <x-auth.field
                            name="date_of_birth"
                            label="Date of Birth"
                            type="date"
                            :required="true"
                            autocomplete="bday"
                            :max="now()->toDateString()"
                        />

                        <div class="mg-field">
                            <label for="sex" class="mg-label">
                                Sex <span class="mg-required" aria-hidden="true">*</span>
                                <span class="visually-hidden">required</span>
                            </label>
                            <select
                                id="sex"
                                name="sex"
                                class="mg-input {{ $errors->has('sex') ? 'is-invalid' : '' }}"
                                required
                                @if ($errors->has('sex')) aria-invalid="true" aria-describedby="sex-error" @endif
                            >
                                <option value="" hidden @selected(old('sex') === null)>Select sex</option>
                                @foreach ($sexOptions as $option)
                                    <option value="{{ $option->value }}" @selected(old('sex') === $option->value)>
                                        {{ $option->label() }}
                                    </option>
                                @endforeach
                            </select>
                            @error('sex')
                                <p id="sex-error" class="mg-field-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <x-auth.field
                            name="contact_number"
                            label="Contact Number"
                            type="tel"
                            :required="true"
                            autocomplete="tel"
                            placeholder="09171234567"
                            inputmode="tel"
                        />
                    </div>

                    <x-auth.field
                        name="email"
                        label="Email Address"
                        type="email"
                        :required="true"
                        autocomplete="email"
                        placeholder="juan@email.com"
                    />

                    <x-auth.field
                        name="password"
                        label="Password"
                        type="password"
                        :required="true"
                        autocomplete="new-password"
                    >
                        <x-slot:addon>
                            <button type="button" class="mg-password-toggle" data-mg-toggle-password="password" aria-label="Show password">
                                <x-patient.icon name="eye" class="mg-password-icon" data-mg-icon="show" />
                                <x-patient.icon name="eye-off" class="mg-password-icon d-none" data-mg-icon="hide" />
                            </button>
                        </x-slot:addon>
                    </x-auth.field>

                    <ul class="mg-password-rules" data-mg-password-rules aria-label="Password requirements">
                        <li data-rule="length">At least 8 characters</li>
                        <li data-rule="upper">One uppercase letter</li>
                        <li data-rule="lower">One lowercase letter</li>
                        <li data-rule="number">One number</li>
                        <li data-rule="special">One special character</li>
                    </ul>

                    <x-auth.field
                        name="password_confirmation"
                        label="Confirm Password"
                        type="password"
                        :required="true"
                        autocomplete="new-password"
                    >
                        <x-slot:addon>
                            <button type="button" class="mg-password-toggle" data-mg-toggle-password="password_confirmation" aria-label="Show password">
                                <x-patient.icon name="eye" class="mg-password-icon" data-mg-icon="show" />
                                <x-patient.icon name="eye-off" class="mg-password-icon d-none" data-mg-icon="hide" />
                            </button>
                        </x-slot:addon>
                    </x-auth.field>

                    <button type="submit" class="mg-auth-submit">
                        Create Patient Account
                    </button>
                </form>

                <p class="mg-auth-footnote">
                    Already have an account?
                    <a href="{{ route('login') }}">Sign in</a>
                </p>
            </div>
        </main>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/register.js') }}"></script>
@endpush
