@props([
    'admin',
])

<header class="mg-header">
    <div class="mg-header-left">
        <button
            class="mg-menu-btn"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#adminSidebar"
            aria-controls="adminSidebar"
            aria-label="Open navigation"
        >
            <x-patient.icon name="menu" width="22" height="22" />
        </button>

        <div>
            <p class="mg-wordmark">MediGuide</p>
            <p class="mg-wordmark-sub">IT Administrator</p>
        </div>
    </div>

    <div class="mg-header-right">
        <div class="dropdown">
            <button
                class="mg-user"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Administrator menu"
            >
                <span class="mg-avatar">{{ $admin['initials'] }}</span>
                <span class="mg-user-meta">
                    <span class="mg-user-name">{{ $admin['name'] }}</span>
                    <span class="mg-user-role">{{ $admin['role'] }}</span>
                </span>
                <x-patient.icon name="chevron-down" class="mg-chevron" width="16" height="16" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end mg-dropdown">
                <li>
                    <span class="dropdown-item-text">
                        Signed in as<br>
                        <strong>{{ $admin['name'] }}</strong>
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
