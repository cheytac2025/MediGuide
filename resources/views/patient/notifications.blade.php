@extends('layouts.patient')

@section('title', 'Notifications')

@section('content')
    @php
        $filters = [
            'all' => 'All',
            'unread' => 'Unread',
            'read' => 'Read',
        ];
        $emptyCopy = match ($filter) {
            'unread' => 'No unread notifications.',
            'read' => 'No read notifications yet.',
            default => null,
        };
    @endphp

    <div class="mg-note-page">
        <section class="mg-panel">
            <header class="mg-note-head">
                <h1 class="mg-panel-title">Notifications</h1>
                <p class="mg-note-subtitle">Stay updated on your appointments.</p>
            </header>

            @if (session('notification_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('notification_status') }}</p>
                </div>
            @endif

            @if (session('notification_error'))
                <div class="mg-book-alert" role="alert">{{ session('notification_error') }}</div>
            @endif

            @if (! $hasNotifications)
                <div class="mg-note-empty">
                    <p class="mg-note-empty-title">You're all caught up.</p>
                    <p>You don't have any notifications right now.</p>
                </div>
            @else
                <div class="mg-note-toolbar">
                    <nav class="mg-note-filters" aria-label="Notification filters">
                        @foreach ($filters as $value => $label)
                            <a
                                href="{{ route('patient.notifications', ['filter' => $value]) }}"
                                @if ($filter === $value) aria-current="true" @endif
                            >{{ $label }}</a>
                        @endforeach
                    </nav>

                    <div class="dropdown">
                        <button
                            type="button"
                            class="mg-note-options"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Notification options"
                        >
                            Options
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end mg-note-menu">
                            <li>
                                <form method="POST" action="{{ route('patient.notifications.read-all') }}">
                                    @csrf
                                    @method('PATCH')
                                    <button
                                        type="submit"
                                        class="dropdown-item"
                                        @disabled(($patient['unread_notifications'] ?? 0) === 0)
                                    >
                                        Mark All as Read
                                    </button>
                                </form>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item" data-mg-note-clear-open>
                                    Clear All Notifications
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>

                @if ($notifications->isEmpty())
                    <p class="mg-note-empty-note">{{ $emptyCopy }}</p>
                @else
                    @foreach ($groups as $group)
                        <section class="mg-note-group" aria-labelledby="note-group-{{ $loop->index }}">
                            <h2 id="note-group-{{ $loop->index }}" class="mg-note-group-label">{{ $group['label'] }}</h2>
                            <ul class="mg-note-list">
                                @foreach ($group['items'] as $notification)
                                    @php
                                        $data = $notification->data;
                                        $isUnread = $notification->unread();
                                        $title = $data['title'] ?? 'Notification';
                                    @endphp
                                    <li class="mg-note-row {{ $isUnread ? 'is-unread' : 'is-read' }}">
                                        <a class="mg-note-open" href="{{ route('patient.notifications.open', $notification) }}">
                                            <span class="mg-note-icon" aria-hidden="true">
                                                <x-patient.icon :name="\App\Support\PatientNotifications::iconName($notification)" width="18" height="18" />
                                            </span>
                                            <span class="mg-note-copy">
                                                <span class="mg-note-title">
                                                    {{ $title }}
                                                    @if ($isUnread)
                                                        <span class="visually-hidden">Unread</span>
                                                    @endif
                                                </span>
                                                @if (! empty($data['message']))
                                                    <span class="mg-note-message">{{ $data['message'] }}</span>
                                                @endif
                                                <span class="mg-note-time">{{ \App\Support\PatientNotifications::timeLabel($notification) }}</span>
                                            </span>
                                            @if ($isUnread)
                                                <span class="mg-note-dot" aria-hidden="true"></span>
                                            @endif
                                        </a>

                                        <div class="dropdown">
                                            <button
                                                type="button"
                                                class="mg-note-menu-btn"
                                                data-bs-toggle="dropdown"
                                                aria-expanded="false"
                                                aria-label="Actions for {{ $title }}"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                                    <circle cx="5" cy="12" r="1.4" fill="currentColor"/>
                                                    <circle cx="12" cy="12" r="1.4" fill="currentColor"/>
                                                    <circle cx="19" cy="12" r="1.4" fill="currentColor"/>
                                                </svg>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end mg-note-menu">
                                                @if ($isUnread)
                                                    <li>
                                                        <form method="POST" action="{{ route('patient.notifications.read', $notification) }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <button type="submit" class="dropdown-item">Mark as Read</button>
                                                        </form>
                                                    </li>
                                                @endif
                                                <li>
                                                    <form method="POST" action="{{ route('patient.notifications.destroy', $notification) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="dropdown-item mg-note-remove">Remove Notification</button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                @endif

                <dialog class="mg-note-dialog" data-mg-note-dialog aria-labelledby="mg-note-clear-title">
                    <h2 id="mg-note-clear-title" class="mg-note-dialog-title">Clear all notifications?</h2>
                    <p>This will remove all notifications from your inbox. Your appointments and appointment history will not be affected.</p>
                    <div class="mg-note-dialog-actions">
                        <button type="button" class="mg-note-dialog-cancel" data-mg-note-clear-cancel>Cancel</button>
                        <form method="POST" action="{{ route('patient.notifications.clear') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="mg-note-dialog-clear">Clear All</button>
                        </form>
                    </div>
                </dialog>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        const dialog = document.querySelector('[data-mg-note-dialog]');
        const openButton = document.querySelector('[data-mg-note-clear-open]');
        const cancelButton = document.querySelector('[data-mg-note-clear-cancel]');

        openButton?.addEventListener('click', () => {
            if (!dialog || typeof dialog.showModal !== 'function') {
                return;
            }

            dialog.showModal();
            cancelButton?.focus();
        });

        cancelButton?.addEventListener('click', () => {
            dialog?.close();
            openButton?.focus();
        });
    </script>
@endpush
