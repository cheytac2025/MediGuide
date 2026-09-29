@extends('layouts.patient')

@section('title', 'My Appointments')

@section('content')
    <div class="mg-book">
        <section class="mg-panel">
            <div class="mg-panel-head">
                <h1 class="mg-panel-title">My Appointments</h1>
            </div>

            @if (is_array($submitted))
                <div class="mg-book-success" role="status">
                    <p>{{ $submitted['message'] }}</p>
                    <dl class="mg-review-list">
                        <div>
                            <dt>Date</dt>
                            <dd>{{ $submitted['date'] }}</dd>
                        </div>
                        <div>
                            <dt>Time</dt>
                            <dd>{{ $submitted['time'] }}</dd>
                        </div>
                        <div>
                            <dt>Doctor</dt>
                            <dd>{{ $submitted['doctor'] }}</dd>
                        </div>
                        <div>
                            <dt>Clinic</dt>
                            <dd>{{ $submitted['clinic'] }}</dd>
                        </div>
                        <div>
                            <dt>Status</dt>
                            <dd>{{ $submitted['status'] }}</dd>
                        </div>
                    </dl>
                </div>
            @else
                <p class="mg-book-empty">Your appointment requests will be listed here.</p>
            @endif
        </section>
    </div>
@endsection
