@extends('layouts.guest')

@section('title', 'Sign In')

@push('head')
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,400;1,500&display=swap" rel="stylesheet">
@endpush

@section('content')
    <div class="mg-login">
        <aside class="mg-login-brand" aria-label="Queen Mary Help of Christians Hospital">
    <img
        src="{{ asset('images/login-brand-panel.jpg') }}"
        alt="Queen Mary Help of Christians Hospital"
        class="mg-login-brand-image"
    >
        </aside>

        <main class="mg-login-panel">
            <div class="mg-login-blob mg-login-blob-d" aria-hidden="true"></div>
            <div class="mg-login-blob mg-login-blob-e" aria-hidden="true"></div>
            <div class="mg-login-blob mg-login-blob-f" aria-hidden="true"></div>

            <p class="mg-login-top-account">
                Don’t have an account?
                <a href="{{ route('register') }}" class="mg-login-create-btn">Create Account</a>
            </p>

            <div class="mg-login-form-wrap">
                <p class="mg-login-wordmark">Medi<span>Guide</span></p>
                <h1 class="mg-login-title">Welcome Back</h1>
                <p class="mg-login-lead">Sign in to continue to MediGuide.</p>

                @if (session('status'))
                    <div class="mg-alert-success" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mg-login-form" novalidate>
                    @csrf

                    <x-auth.field
                        name="email"
                        label="Email Address"
                        type="email"
                        :required="true"
                        autocomplete="email"
                        placeholder="Enter your email address"
                        autofocus
                    >
                        <x-slot:prefix>
                            <span class="mg-input-icon" aria-hidden="true">
                                <x-patient.icon name="mail" />
                            </span>
                        </x-slot:prefix>
                    </x-auth.field>

                    <x-auth.field
                        name="password"
                        label="Password"
                        type="password"
                        :required="true"
                        autocomplete="current-password"
                        placeholder="Enter your password"
                    >
                        <x-slot:prefix>
                            <span class="mg-input-icon" aria-hidden="true">
                                <x-patient.icon name="lock" />
                            </span>
                        </x-slot:prefix>
                        <x-slot:addon>
                            <button type="button" class="mg-password-toggle" data-mg-toggle-password="password" aria-label="Show password">
                                <x-patient.icon name="eye" class="mg-password-icon" data-mg-icon="show" />
                                <x-patient.icon name="eye-off" class="mg-password-icon d-none" data-mg-icon="hide" />
                            </button>
                        </x-slot:addon>
                    </x-auth.field>

                    <div class="mg-login-options">
                        <label class="mg-check" for="remember">
                            <input
                                type="checkbox"
                                id="remember"
                                name="remember"
                                value="1"
                                @checked(old('remember'))
                            >
                            Remember Me
                        </label>

                        <a href="#" class="mg-auth-forgot">Forgot Password?</a>
                    </div>

                    <button type="submit" class="mg-login-submit">
                        Sign In
                        <x-patient.icon name="arrow-right" class="mg-login-submit-icon" />
                    </button>
                </form>

                <div class="mg-login-or" role="separator" aria-label="or">
                    <span>or</span>
                </div>

                <p class="mg-login-footnote">
                    Don’t have an account?
                    <a href="{{ route('register') }}">Create Account</a>
                </p>
            </div>

            <p class="mg-login-mission">Your Health.<br>Our Mission.</p>
        </main>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/register.js') }}"></script>
@endpush
