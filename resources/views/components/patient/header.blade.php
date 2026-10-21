@props([
    'patient',
])

<header class="mg-header">
    <div class="mg-header-left">
        <button
            class="mg-menu-btn"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#patientSidebar"
            aria-controls="patientSidebar"
            aria-label="Open navigation"
        >
            <x-patient.icon name="menu" width="22" height="22" />
        </button>

        <div>
            <p class="mg-wordmark">MediGuide</p>
            <p class="mg-wordmark-sub">Your Health. Our Guidance.</p>
        </div>
    </div>

    <div class="mg-header-right">
        <div class="dropdown">
            <button
                type="button"
                class="mg-icon-btn"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Notifications"
            >
                <x-patient.icon name="bell" width="22" height="22" />
                @if (($patient['unread_notifications'] ?? 0) > 0)
                    <span class="mg-badge-dot">{{ $patient['unread_notifications'] }}</span>
                @endif
            </button>
            <div class="dropdown-menu dropdown-menu-end mg-bell-menu">
                <p class="mg-bell-heading">Notifications</p>
                @forelse (($patient['recent_notifications'] ?? []) as $item)
                    <a class="mg-bell-item {{ $item['unread'] ? 'is-unread' : 'is-read' }}" href="{{ $item['url'] }}">
                        <span class="mg-bell-item-title">
                            {{ $item['title'] }}
                            @if ($item['unread'])
                                <span class="visually-hidden">Unread</span>
                            @endif
                        </span>
                        @if ($item['message'] !== '')
                            <span class="mg-bell-item-message">{{ $item['message'] }}</span>
                        @endif
                        <span class="mg-bell-item-time">{{ $item['time'] }}</span>
                        @if ($item['unread'])
                            <span class="mg-bell-dot" aria-hidden="true"></span>
                        @endif
                    </a>
                @empty
                    <p class="mg-bell-empty">You're all caught up.</p>
                @endforelse
                <a class="mg-bell-all" href="{{ route('patient.notifications') }}">View All Notifications</a>
            </div>
        </div>

        <div class="dropdown">
            <button
                class="mg-user"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                aria-label="Patient menu"
            >
                <span class="mg-avatar">{{ $patient['initials'] }}</span>
                <span class="mg-user-meta">
                    <span class="mg-user-name">{{ $patient['name'] }}</span>
                    <span class="mg-user-role">{{ $patient['role'] }}</span>
                </span>
                <x-patient.icon name="chevron-down" class="mg-chevron" width="16" height="16" />
            </button>
            <ul class="dropdown-menu dropdown-menu-end mg-dropdown">
                <li>
                    <span class="dropdown-item-text">
                        Signed in as<br>
                        <strong>{{ $patient['name'] }}</strong>
                    </span>
                </li>
                <li><a class="dropdown-item" href="{{ route('patient.profile') }}">Profile</a></li>
                <li><a class="dropdown-item" href="#">Help &amp; Support</a></li>
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
