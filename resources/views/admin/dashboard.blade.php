@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <section class="mg-hero">
        <div class="mg-hero-copy">
            <h1 class="mg-greeting">Good day, {{ $admin['first_name'] }}!</h1>
            <p class="mg-greeting-sub">Review hospital setup and account totals for MediGuide.</p>
        </div>
    </section>

    <section class="mg-staff-stats" aria-label="System summary">
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Active Clinics</p>
            <p class="mg-staff-stat-value" data-stat="clinics">{{ $activeClinicCount }}</p>
        </article>
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Active Doctors</p>
            <p class="mg-staff-stat-value" data-stat="doctors">{{ $activeDoctorCount }}</p>
        </article>
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Hospital Staff Accounts</p>
            <p class="mg-staff-stat-value" data-stat="staff">{{ $hospitalStaffCount }}</p>
        </article>
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Doctor Accounts</p>
            <p class="mg-staff-stat-value" data-stat="doctor-accounts">{{ $doctorAccountCount }}</p>
        </article>
        <article class="mg-staff-stat">
            <p class="mg-staff-stat-label">Patient Accounts</p>
            <p class="mg-staff-stat-value" data-stat="patients">{{ $patientAccountCount }}</p>
        </article>
    </section>

    <section class="mg-panel mt-3">
        <div class="mg-panel-head">
            <h2 class="mg-panel-title">
                <x-patient.icon name="clipboard" />
                System Overview
            </h2>
        </div>

        <div class="mg-staff-request-list">
            <article class="mg-staff-request-card">
                <h3>Clinic Management</h3>
                <p>IT Administrators will create and maintain clinics for active hospital departments.</p>
            </article>
            <article class="mg-staff-request-card">
                <h3>Doctor Management</h3>
                <p>IT Administrators will create doctor records and link them to clinic assignments.</p>
            </article>
            <article class="mg-staff-request-card">
                <h3>User Account Management</h3>
                <p>IT Administrators will create Hospital Staff and Doctor accounts. Public registration remains for patients only.</p>
            </article>
        </div>
    </section>
@endsection
