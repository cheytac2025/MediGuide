@extends('layouts.patient')

@section('title', 'Notifications')

@section('content')
    <div class="mg-notifications-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">Notifications</h1>
                    <p class="mg-appt-subtitle">Stay updated on your appointment requests.</p>
                </div>

                @if ($notifications->isNotEmpty() && $notifications->contains(fn ($item) => $item->unread()))
                    <form method="POST" action="{{ route('patient.notifications.read-all') }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="mg-appt-details">Mark All as Read</button>
                    </form>
                @endif
            </div>

            @if (session('notification_status'))
                <div class="mg-book-success" role="status">
                    <p>{{ session('notification_status') }}</p>
                </div>
            @endif

            @if (session('notification_error'))
                <div class="mg-book-alert" role="alert">{{ session('notification_error') }}</div>
            @endif

            @if ($notifications->isEmpty())
                <p class="mg-book-empty">No notifications yet.</p>
            @else
                <ul class="mg-notification-list">
                    @foreach ($notifications as $notification)
                        @php
                            $data = $notification->data;
                            $isUnread = $notification->unread();
                        @endphp
                        <li class="mg-notification-item {{ $isUnread ? 'is-unread' : 'is-read' }}">
                            <div class="mg-notification-main">
                                <div class="mg-notification-top">
                                    <h2 class="mg-notification-title">{{ $data['title'] ?? 'Notification' }}</h2>
                                    @if ($isUnread)
                                        <span class="mg-notification-badge">Unread</span>
                                    @endif
                                </div>
                                <p class="mg-notification-message">{{ $data['message'] ?? '' }}</p>
                                <p class="mg-notification-time">{{ $notification->created_at?->diffForHumans() }}</p>
                            </div>

                            <div class="mg-notification-actions">
                                @if (! empty($data['appointment_id']))
                                    <a
                                        class="mg-appt-details"
                                        href="{{ route('patient.notifications.open', $notification) }}"
                                    >
                                        View Appointment
                                    </a>
                                @endif

                                @if ($isUnread)
                                    <form method="POST" action="{{ route('patient.notifications.read', $notification) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="mg-recommend-link">Mark as Read</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
