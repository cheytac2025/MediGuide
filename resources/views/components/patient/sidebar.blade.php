@props([
    'patient',
    'active' => 'home',
])

@php
    $links = [
        ['key' => 'home', 'label' => 'Home', 'icon' => 'home', 'route' => 'patient.dashboard'],
        ['key' => 'front-desk', 'label' => 'AI Virtual Front Desk', 'icon' => 'chat', 'route' => 'patient.ai-front-desk'],
        ['key' => 'book', 'label' => 'Book Appointment', 'icon' => 'calendar', 'href' => '#'],
        ['key' => 'appointments', 'label' => 'My Appointments', 'icon' => 'clipboard', 'href' => '#'],
        ['key' => 'notifications', 'label' => 'Notifications', 'icon' => 'bell', 'href' => '#'],
        ['key' => 'profile', 'label' => 'Profile', 'icon' => 'user', 'href' => '#'],
        ['key' => 'help', 'label' => 'Help & Support', 'icon' => 'help', 'href' => '#'],
    ];
@endphp

<aside
    class="mg-sidebar offcanvas-lg offcanvas-start"
    tabindex="-1"
    id="patientSidebar"
    aria-label="Patient navigation"
>
    <button
        type="button"
        class="btn-close mg-offcanvas-close d-lg-none"
        data-bs-dismiss="offcanvas"
        data-bs-target="#patientSidebar"
        aria-label="Close navigation"
    ></button>

    <div class="mg-sidebar-brand">
        <x-queen-mary-logo class="mg-sidebar-logo" />

        <p class="mg-hospital-name">
            Queen Mary
            <span>Help of Christians Hospital</span>
        </p>
        <p class="mg-hospital-tagline">Compassion. Care. For a Healthier Tomorrow.</p>
    </div>

    <nav class="mg-nav" aria-label="Primary">
        @foreach ($links as $link)
            @php
                $isActive = $active === $link['key'];
                $href = isset($link['route']) ? route($link['route']) : $link['href'];
            @endphp

            <a
                href="{{ $href }}"
                class="mg-nav-link {{ $isActive ? 'active' : '' }}"
                @if ($isActive) aria-current="page" @endif
            >
                <x-patient.icon :name="$isActive && $link['icon'] === 'home' ? 'home-solid' : $link['icon']" class="mg-nav-icon" />
                <span>{{ $link['label'] }}</span>
                @if ($link['key'] === 'notifications' && ($patient['unread_notifications'] ?? 0) > 0)
                    <span class="mg-nav-dot" aria-hidden="true"></span>
                @endif
            </a>
        @endforeach
    </nav>

    <div class="mg-sidebar-footer">
        <img
            src="{{ asset('images/virgin-mary.png') }}"
            alt=""
            class="mg-mary"
        >
        <p class="mg-sidebar-motto">
            Guided by Faith.<br>
            Committed to Care.<br>
            Here for You.
        </p>
    </div>
</aside>
