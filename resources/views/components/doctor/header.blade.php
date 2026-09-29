@props([
    'doctor',
])

<header class="mg-header">
    <div class="mg-header-left">
        <button
            class="mg-menu-btn"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#doctorSidebar"
            aria-controls="doctorSidebar"
            aria-label="Open navigation"
        >
            <x-patient.icon name="menu" width="22" height="22" />
        </button>

        <div>
            <p class="mg-wordmark">MediGuide</p>
            <p class="mg-wordmark-sub">Doctor Portal</p>
        </div>
    </div>

    <div class="mg-header-right">
        <div class="dropdown">
            <button
                class="mg-user"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Doctor menu"
            >
                <span class="mg-avatar">{{ $doctor['initials'] }}</span>
                <span class="mg-user-meta">
                    <span class="mg-user-name">{{ $doctor['name'] }}</span>
                    <span class="mg-user-role">{{ $doctor['role'] }}</span>
                </span>
                <x-patient.icon name="chevron-down" class="mg-chevron" width="16" height="16" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end mg-dropdown">
                <li>
                    <span class="dropdown-item-text">
                        Signed in as<br>
                        <strong>{{ $doctor['display_name'] }}</strong>
                    </span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">Log out</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
