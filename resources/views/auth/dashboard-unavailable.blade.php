@extends('layouts.guest')

@section('title', 'Dashboard')

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
            </div>
        </aside>

        <main class="mg-auth-panel">
            <div class="mg-auth-card">
                <p class="mg-wordmark">MediGuide</p>
                <p class="mg-wordmark-sub">Your Health. Our Guidance.</p>

                <h1 class="mg-auth-title">Dashboard</h1>
                <p class="mg-auth-lead">Your dashboard is currently under development.</p>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="mg-auth-submit">Sign Out</button>
                </form>
            </div>
        </main>
    </div>
@endsection
