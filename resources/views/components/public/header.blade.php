@props([
    'active' => '',
])

<header class="mg-public-header">
    <a href="{{ route('home') }}" class="mg-public-brand">
        <x-queen-mary-logo class="mg-public-logo" />
        <span class="mg-public-brand-copy">
            <span class="mg-public-hospital">Queen Mary Help of Christians Hospital</span>
            <span class="mg-wordmark">MediGuide</span>
        </span>
    </a>

    <nav class="mg-public-nav" aria-label="Public">
        <a
            href="{{ route('ai-front-desk') }}"
            class="mg-public-nav-link {{ $active === 'front-desk' ? 'is-active' : '' }}"
            @if ($active === 'front-desk') aria-current="page" @endif
        >
            AI Virtual Front Desk
        </a>

        @auth
            <span class="mg-public-user">{{ auth()->user()->first_name ?: auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}" class="mg-public-logout">
                @csrf
                <button type="submit" class="mg-public-btn mg-public-btn-ghost">Log out</button>
            </form>
        @else
            <a href="{{ route('login') }}" class="mg-public-btn mg-public-btn-ghost">Log In</a>
            <a href="{{ route('register') }}" class="mg-public-btn mg-public-btn-primary">Create Account</a>
        @endauth
    </nav>
</header>
