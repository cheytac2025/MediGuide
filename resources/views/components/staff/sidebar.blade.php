@props([
    'staff',
    'active' => 'dashboard',
])

@php
    $links = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'route' => 'staff.dashboard'],
        ['key' => 'appointments', 'label' => 'Appointments', 'icon' => 'clipboard', 'route' => 'staff.appointments'],
    ];
@endphp

<aside
    class="mg-sidebar offcanvas-lg offcanvas-start"
    tabindex="-1"
    id="staffSidebar"
    aria-label="Hospital staff navigation"
>
    <button
        type="button"
        class="btn-close mg-offcanvas-close d-lg-none"
        data-bs-dismiss="offcanvas"
        data-bs-target="#staffSidebar"
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
            @endphp

            <a
                href="{{ route($link['route']) }}"
                class="mg-nav-link {{ $isActive ? 'active' : '' }}"
                @if ($isActive) aria-current="page" @endif
            >
                <x-patient.icon :name="$isActive && $link['icon'] === 'home' ? 'home-solid' : $link['icon']" class="mg-nav-icon" />
                <span>{{ $link['label'] }}</span>
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
