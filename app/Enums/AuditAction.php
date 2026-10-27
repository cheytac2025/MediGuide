<?php

namespace App\Enums;

/**
 * Stable identifiers for security-relevant actions. Values are stored in the
 * audit chain and must not be renamed after events exist.
 */
enum AuditAction: string
{
    case LoginSucceeded = 'auth.login.succeeded';
    case LoginFailed = 'auth.login.failed';
    case LoginInactive = 'auth.login.inactive';
    case Logout = 'auth.logout';
    case InactiveSessionDenied = 'auth.inactive_session.denied';
    case PasskeySucceeded = 'auth.passkey.succeeded';
    case PasskeyFailed = 'auth.passkey.failed';
    case StaffAccountCreated = 'admin.staff.created';
    case AccountStatusChanged = 'admin.account.status_changed';
    case DepartmentAssignmentChanged = 'admin.staff.departments_changed';
    case ClinicCreated = 'admin.clinic.created';
    case ClinicUpdated = 'admin.clinic.updated';
    case ClinicStatusChanged = 'admin.clinic.status_changed';
    case DoctorCreated = 'admin.doctor.created';
    case DoctorUpdated = 'admin.doctor.updated';
    case DoctorStatusChanged = 'admin.doctor.status_changed';
    case AppointmentConfirmed = 'staff.appointment.confirmed';
    case AppointmentRejected = 'staff.appointment.rejected';
    case ScheduleCreated = 'staff.schedule.created';
    case ScheduleUpdated = 'staff.schedule.updated';
    case ScheduleStatusChanged = 'staff.schedule.status_changed';
    case AppointmentCreated = 'patient.appointment.created';
    case AppointmentCancelled = 'patient.appointment.cancelled';
    case IntegrityFailed = 'security.integrity.failed';
    case AuthorizationDenied = 'security.authorization.denied';
}
