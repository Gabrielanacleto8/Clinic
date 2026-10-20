<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A auditoria passa a guardar o tipo do registro pelo apelido do morph map
     * (AppServiceProvider), e não pelo nome da classe. Os registros já gravados
     * acompanham, para o histórico continuar consultável por tipo.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'App\Models\Appointment' => 'appointment',
        'App\Models\AuditLog' => 'audit_log',
        'App\Models\FinancialEntry' => 'financial_entry',
        'App\Models\FinancialInstallment' => 'financial_installment',
        'App\Models\Patient' => 'patient',
        'App\Models\PatientAnamnesis' => 'patient_anamnesis',
        'App\Models\PatientAttachment' => 'patient_attachment',
        'App\Models\Procedure' => 'procedure',
        'App\Models\Product' => 'product',
        'App\Models\ProductBatch' => 'product_batch',
        'App\Models\StockMovement' => 'stock_movement',
        'App\Models\Treatment' => 'treatment',
        'App\Models\TreatmentNote' => 'treatment_note',
        'App\Models\TreatmentProduct' => 'treatment_product',
        'App\Models\User' => 'user',
    ];

    public function up(): void
    {
        foreach (self::ALIASES as $class => $alias) {
            DB::table('audit_logs')->where('auditable_type', $class)->update(['auditable_type' => $alias]);
        }
    }

    public function down(): void
    {
        foreach (self::ALIASES as $class => $alias) {
            DB::table('audit_logs')->where('auditable_type', $alias)->update(['auditable_type' => $class]);
        }
    }
};
