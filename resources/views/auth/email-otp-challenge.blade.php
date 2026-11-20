@extends('layouts.guest')

@section('title', 'Sign-in Code')

@section('content')
    <div class="mg-login">
        <main class="mg-login-panel" style="margin: 0 auto;">
            <div class="mg-login-form-wrap">
                <p class="mg-login-wordmark">Medi<span>Guide</span></p>
                <h1 class="mg-login-title">Check your email</h1>
                <p class="mg-login-lead">
                    Enter the 6-digit code sent to {{ $emailHint }}.
                </p>

                @if (session('status'))
                    <div class="mg-alert-success" role="status">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('auth.email-otp.verify') }}" class="mg-login-form" novalidate>
                    @csrf
                    <x-auth.field
                        name="code"
                        label="Sign-in code"
                        type="text"
                        :required="true"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        placeholder="000000"
                        autofocus
                    />
                    <button type="submit" class="mg-login-submit">Verify and continue</button>
                </form>

                <form method="POST" action="{{ route('auth.email-otp.resend') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="mg-login-back">Resend code</button>
                </form>

                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="mg-login-back">Cancel and sign out</button>
                </form>
            </div>
        </main>
    </div>
@endsection
