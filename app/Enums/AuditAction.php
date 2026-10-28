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
    case PermissionsViewed = 'permissions.viewed';
    case AuditViewed = 'audit.viewed';
    case ClinicProfileUpdated = 'clinic.profile_updated';
    case ClinicLogoUpdated = 'clinic.logo_updated';
    case ClinicLogoRemoved = 'clinic.logo_removed';

    case PatientViewed = 'patient.viewed';
    case PatientCreated = 'patient.created';
    case PatientUpdated = 'patient.updated';
    case PatientArchived = 'patient.archived';
    case PatientRestored = 'patient.restored';
    case PatientLookup = 'patient.lookup';
    case PatientRecordPrinted = 'patient.record_printed';
    case ConsentTermPrinted = 'patient.consent_term_printed';

    case AppointmentCreated = 'appointment.created';
    case AppointmentUpdated = 'appointment.updated';
    case AppointmentStatusChanged = 'appointment.status_changed';
    case AgendaViewed = 'agenda.viewed';

    case FinancialEntryCreated = 'finance.entry_created';
    case FinancialEntryDeleted = 'finance.entry_deleted';
    case FinancialEntryUpdated = 'finance.entry_updated';
    case InstallmentPaid = 'finance.installment_paid';
    case InstallmentReopened = 'finance.installment_reopened';
    case FinanceViewed = 'finance.viewed';
    case FinanceExported = 'finance.exported';
    case FinanceMarginViewed = 'finance.margin_viewed';

    case ProcedureCreated = 'procedure.created';
    case ProcedureUpdated = 'procedure.updated';

    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case StockMoved = 'stock.moved';
    case StockUnitOpened = 'stock.unit_opened';
    case ProductBatchUpdated = 'stock.batch_updated';
    case StockCounted = 'stock.counted';
    case StockEntryCostCorrected = 'stock.entry_cost_corrected';

    case AnamnesisUpdated = 'anamnesis.updated';
    case AnamnesisReviewed = 'anamnesis.reviewed';
    case AnamnesisVersionViewed = 'anamnesis.version_viewed';

    case TreatmentsListed = 'treatment.listed';
    case TreatmentViewed = 'treatment.viewed';
    case TreatmentCreated = 'treatment.created';
    case TreatmentVoided = 'treatment.voided';
    // Do tempo em que o atendimento tinha rascunho. Não são mais gravadas:
    // ficam para a auditoria já registrada continuar legível.
    case TreatmentUpdated = 'treatment.updated';
    case TreatmentDiscarded = 'treatment.discarded';
    case TreatmentFinalized = 'treatment.finalized';
    case TreatmentNoteAdded = 'treatment.note_added';
    case TreatmentPlanCreated = 'plan.created';
    case TreatmentPlanUpdated = 'plan.updated';
    case TreatmentPlanViewed = 'plan.viewed';
    case TreatmentPlansListed = 'plan.listed';
    case TreatmentPlanPrinted = 'plan.printed';
    case TreatmentPlanApproved = 'plan.approved';
    case TreatmentPlanDeclined = 'plan.declined';
    case TreatmentPlanClosed = 'plan.closed';

    case PhotoUploaded = 'photo.uploaded';
    case PhotoViewed = 'photo.viewed';
    case PhotoDeleted = 'photo.deleted';

    case DocumentUploaded = 'document.uploaded';
    case DocumentViewed = 'document.viewed';
    case DocumentDeleted = 'document.deleted';

    /**
     * Como a ação aparece na tela de auditoria.
     */
    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Entrou no sistema',
            self::LoginFailed => 'Tentativa de acesso com senha errada',
            self::LoginLockedOut => 'Acesso bloqueado por tentativas',
            self::Logout => 'Saiu do sistema',
            self::PasswordChanged => 'Trocou a própria senha',
            self::UserCreated => 'Cadastrou usuário',
            self::UserUpdated => 'Alterou usuário',
            self::UserPasswordReset => 'Redefiniu a senha de usuário',
            self::PermissionsUpdated => 'Alterou permissões',
            self::PermissionsViewed => 'Consultou permissões',
            self::AuditViewed => 'Consultou a auditoria',
            self::ClinicProfileUpdated => 'Alterou os dados da clínica',
            self::ClinicLogoUpdated => 'Trocou o logo da clínica',
            self::ClinicLogoRemoved => 'Removeu o logo da clínica',
            self::PatientViewed => 'Abriu a ficha do paciente',
            self::PatientCreated => 'Cadastrou paciente',
            self::PatientUpdated => 'Alterou cadastro do paciente',
            self::PatientArchived => 'Arquivou paciente',
            self::PatientRestored => 'Restaurou paciente',
            self::PatientLookup => 'Encontrou cadastro existente ao digitar um CPF',
            self::PatientRecordPrinted => 'Imprimiu o prontuário',
            self::ConsentTermPrinted => 'Imprimiu termo de consentimento',
            self::AppointmentCreated => 'Criou agendamento',
            self::AppointmentUpdated => 'Remarcou agendamento',
            self::AppointmentStatusChanged => 'Mudou a situação do agendamento',
            self::AgendaViewed => 'Consultou a agenda',
            self::FinancialEntryCreated => 'Criou lançamento financeiro',
            self::FinancialEntryDeleted => 'Excluiu lançamento financeiro',
            self::FinancialEntryUpdated => 'Alterou lançamento financeiro',
            self::InstallmentPaid => 'Registrou pagamento',
            self::InstallmentReopened => 'Desfez pagamento',
            self::FinanceViewed => 'Consultou o financeiro',
            self::FinanceExported => 'Exportou a planilha do financeiro',
            self::FinanceMarginViewed => 'Consultou a margem por procedimento',
            self::ProcedureCreated => 'Cadastrou procedimento',
            self::ProcedureUpdated => 'Alterou procedimento',
            self::ProductCreated => 'Cadastrou produto',
            self::ProductUpdated => 'Alterou produto',
            self::StockMoved => 'Movimentou o estoque',
            self::StockUnitOpened => 'Abriu unidade de produto',
            self::ProductBatchUpdated => 'Corrigiu dados do lote',
            self::StockCounted => 'Conferiu o inventário',
            self::StockEntryCostCorrected => 'Corrigiu o custo de uma entrada',
            self::AnamnesisUpdated => 'Alterou a ficha de saúde',
            self::AnamnesisReviewed => 'Revisou a ficha de saúde sem alteração',
            self::AnamnesisVersionViewed => 'Consultou versão anterior da ficha de saúde',
            self::TreatmentsListed => 'Consultou a lista de atendimentos',
            self::TreatmentViewed => 'Abriu atendimento',
            self::TreatmentCreated => 'Registrou atendimento',
            self::TreatmentVoided => 'Anulou atendimento',
            self::TreatmentUpdated => 'Alterou atendimento (versão antiga)',
            self::TreatmentDiscarded => 'Descartou rascunho (versão antiga)',
            self::TreatmentFinalized => 'Finalizou atendimento (versão antiga)',
            self::TreatmentNoteAdded => 'Registrou evolução',
            self::TreatmentPlanCreated => 'Criou orçamento',
            self::TreatmentPlanUpdated => 'Alterou orçamento',
            self::TreatmentPlanViewed => 'Abriu orçamento',
            self::TreatmentPlansListed => 'Consultou a lista de orçamentos',
            self::TreatmentPlanPrinted => 'Imprimiu orçamento',
            self::TreatmentPlanApproved => 'Aprovou orçamento (gerou o pacote e a cobrança)',
            self::TreatmentPlanDeclined => 'Marcou orçamento como recusado',
            self::TreatmentPlanClosed => 'Encerrou pacote',
            self::PhotoUploaded => 'Enviou foto',
            self::PhotoViewed => 'Abriu foto',
            self::PhotoDeleted => 'Removeu foto',
            self::DocumentUploaded => 'Enviou documento',
            self::DocumentViewed => 'Baixou documento',
            self::DocumentDeleted => 'Removeu documento',
            // Ações novas sem rótulo ainda aparecem pelo código.
            default => $this->value,
        };
    }

    /**
     * @return array<string, string> Valor => rótulo, em ordem alfabética do rótulo.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        asort($options);

        return $options;
    }
}
