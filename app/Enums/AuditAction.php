<?php

namespace App\Enums;

enum AuditAction: string
{
    case LoginSucceeded = 'auth.login_succeeded';
    case LoginFailed = 'auth.login_failed';
    case LoginLockedOut = 'auth.login_locked_out';
    case Logout = 'auth.logout';
    case PasswordChanged = 'auth.password_changed';

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserPasswordReset = 'user.password_reset';
    case PermissionsUpdated = 'permissions.updated';

    case PatientViewed = 'patient.viewed';
    case PatientCreated = 'patient.created';
    case PatientUpdated = 'patient.updated';
    case PatientArchived = 'patient.archived';
    case PatientRestored = 'patient.restored';

    case AppointmentCreated = 'appointment.created';
    case AppointmentUpdated = 'appointment.updated';
    case AppointmentStatusChanged = 'appointment.status_changed';

    case FinancialEntryCreated = 'finance.entry_created';
    case FinancialEntryDeleted = 'finance.entry_deleted';
    case InstallmentPaid = 'finance.installment_paid';
    case InstallmentReopened = 'finance.installment_reopened';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case StockMoved = 'stock.moved';

    case AnamnesisUpdated = 'anamnesis.updated';

    case TreatmentViewed = 'treatment.viewed';
    case TreatmentCreated = 'treatment.created';
    case TreatmentUpdated = 'treatment.updated';
    case TreatmentDiscarded = 'treatment.discarded';
    case TreatmentFinalized = 'treatment.finalized';
    case TreatmentNoteAdded = 'treatment.note_added';

    case PhotoUploaded = 'photo.uploaded';
    case PhotoViewed = 'photo.viewed';
    case PhotoDeleted = 'photo.deleted';

    case DocumentUploaded = 'document.uploaded';
    case DocumentViewed = 'document.viewed';
    case DocumentDeleted = 'document.deleted';
}
