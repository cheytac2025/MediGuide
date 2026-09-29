@extends('layouts.patient')

@section('title', 'My Profile')

@section('content')
    <div class="mg-profile-page">
        <section class="mg-panel">
            <div class="mg-appt-head">
                <div>
                    <h1 class="mg-panel-title">My Profile</h1>
                    <p class="mg-appt-subtitle">View your registered patient and account information.</p>
                </div>
            </div>

            <div class="mg-profile-grid">
                <section class="mg-profile-card" aria-labelledby="mg-profile-personal">
                    <h2 id="mg-profile-personal" class="mg-profile-card-title">Personal Information</h2>
                    <dl class="mg-review-list">
                        <div>
                            <dt>Full Name</dt>
                            <dd>{{ $profile['full_name'] }}</dd>
                        </div>
                        <div>
                            <dt>Date of Birth</dt>
                            <dd>{{ $profile['date_of_birth'] }}</dd>
                        </div>
                        <div>
                            <dt>Sex</dt>
                            <dd>{{ $profile['sex'] }}</dd>
                        </div>
                        <div>
                            <dt>Contact Number</dt>
                            <dd>{{ $profile['contact_number'] }}</dd>
                        </div>
                        <div>
                            <dt>Email Address</dt>
                            <dd>{{ $profile['email'] }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="mg-profile-card" aria-labelledby="mg-profile-account">
                    <h2 id="mg-profile-account" class="mg-profile-card-title">Account Information</h2>
                    <dl class="mg-review-list">
                        <div>
                            <dt>Role</dt>
                            <dd>{{ $profile['role'] }}</dd>
                        </div>
                        <div>
                            <dt>Account Status</dt>
                            <dd>{{ $profile['account_status'] }}</dd>
                        </div>
                    </dl>
                </section>
            </div>

            <p class="mg-profile-note">
                To request corrections to your registered information, please contact authorized hospital personnel.
            </p>
        </section>
    </div>
@endsection
