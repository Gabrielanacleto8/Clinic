<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicProfile;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Models\PatientAnamnesis;
use App\Models\PatientAnamnesisVersion;
use App\Models\PatientAttachment;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Treatment;
use App\Models\TreatmentNote;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\TreatmentProduct;
use App\Models\User;
use App\Services\RolePermissions;
use App\Support\ProductionConfig;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Uma instância por requisição: as permissões são lidas do banco uma única vez.
        $this->app->scoped(RolePermissions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $isProduction = $this->app->isProduction();

        // A auditoria guarda o tipo do registro por este apelido, e não pelo nome
        // da classe: renomear um modelo não pode deixar o histórico órfão. Modelo
        // novo entra aqui antes de ser auditado, senão getMorphClass() falha.
        Relation::enforceMorphMap([
            'appointment' => Appointment::class,
            'audit_log' => AuditLog::class,
            'clinic_profile' => ClinicProfile::class,
            'financial_entry' => FinancialEntry::class,
            'financial_installment' => FinancialInstallment::class,
            'patient' => Patient::class,
            'patient_anamnesis' => PatientAnamnesis::class,
            'patient_anamnesis_version' => PatientAnamnesisVersion::class,
            'patient_attachment' => PatientAttachment::class,
            'procedure' => Procedure::class,
            'product' => Product::class,
            'product_batch' => ProductBatch::class,
            'stock_movement' => StockMovement::class,
            'stock_unit' => StockUnit::class,
            'treatment' => Treatment::class,
            'treatment_note' => TreatmentNote::class,
            'treatment_plan' => TreatmentPlan::class,
            'treatment_plan_item' => TreatmentPlanItem::class,
            'treatment_product' => TreatmentProduct::class,
            'user' => User::class,
        ]);

        // Fora de produção, falha cedo em lazy loading (N+1), atributos
        // inexistentes e mass assignment silenciosamente descartado.
        Model::shouldBeStrict(! $isProduction);

        // Bloqueia migrate:fresh, db:wipe e similares em produção.
        DB::prohibitDestructiveCommands($isProduction);

        Date::use(CarbonImmutable::class);

        if ($isProduction) {
            URL::forceHttps();

            // Falha cedo, com a causa no log, em vez de quebrar no primeiro
            // paciente gravado. No console, o comando clinic:check faz a mesma
            // conferência sem impedir os comandos de instalação.
            if (! $this->app->runningInConsole() && ($problems = ProductionConfig::problems()) !== []) {
                throw new RuntimeException('Configuração de produção incompleta. '.implode(' ', $problems));
            }
        }

        // Definir o que cada perfil acessa é exclusivo do administrador e não é delegável.
        Gate::define('manage-permissions', fn (User $user) => $user->isAdmin());

        // Dados e logo da clínica, impressos nos documentos de todos os pacientes.
        Gate::define('manage-clinic', fn (User $user) => $user->isAdmin());

        // Teto por IP no login, somado ao bloqueio por e-mail + IP do LoginRequest.
        // Freia quem testa uma mesma senha contra muitos e-mails diferentes.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        Password::defaults(function () use ($isProduction) {
            // Mínimo de 8 caracteres, com maiúscula, minúscula e número. Símbolos são opcionais.
            $rule = Password::min(8)->letters()->mixedCase()->numbers();

            // Consulta vazamentos conhecidos (k-anonymity) apenas em produção.
            return $isProduction ? $rule->uncompromised() : $rule;
        });
    }
}
