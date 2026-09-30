@props([
    'admin',
    'active' => 'dashboard',
])

@php
    $links = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'route' => 'admin.dashboard', 'enabled' => true],
        ['key' => 'clinics', 'label' => 'Clinics', 'icon' => 'badge', 'route' => 'admin.clinics', 'enabled' => true],
        ['key' => 'doctors', 'label' => 'Doctors', 'icon' => 'user', 'enabled' => false],
        ['key' => 'accounts', 'label' => 'User Accounts', 'icon' => 'clipboard', 'enabled' => false],
    ];
@endphp

<aside
    class="mg-sidebar offcanvas-lg offcanvas-start"
    tabindex="-1"
    id="adminSidebar"
    aria-label="IT administrator navigation"
>
    <button
        type="button"
        class="btn-close mg-offcanvas-close d-lg-none"
        data-bs-dismiss="offcanvas"
        data-bs-target="#adminSidebar"
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
            @if ($link['enabled'])
                @php
                    $isActive = $active === $link['key'];
                @endphp
                <a
                    href="{{ route($link['route']) }}"
                    class="mg-nav-link {{ $isActive ? 'active' : '' }}"
                    @if ($isActive) aria-current="page" @endif
                >
                    <x-patient.icon :name="$isActive && $link['icon'] === 'home' ? 'home-solid' : $link['icon']" class="mg-nav-icon" />
                    <span>{{ $link['label'] }}</span>
                </a>
            @else
                <span class="mg-nav-link is-disabled" aria-disabled="true">
                    <x-patient.icon :name="$link['icon']" class="mg-nav-icon" />
                    <span>{{ $link['label'] }}</span>
                </span>
            @endif
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
