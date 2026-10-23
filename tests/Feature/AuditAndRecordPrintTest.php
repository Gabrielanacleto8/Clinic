<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAnamnesis;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tela de auditoria (só administrador) e prontuário para impressão.
 */
class AuditAndRecordPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    public function test_only_the_administrator_reads_the_audit_trail(): void
    {
        $this->get('/auditoria')->assertRedirect('/login');

        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user)->get('/auditoria')->assertForbidden();
        }

        $this->actingAs(User::factory()->admin()->create())->get('/auditoria')->assertOk()->assertSee('Auditoria');
        $this->assertSame(1, AuditLog::where('action', AuditAction::AuditViewed->value)->count());
    }

    public function test_patient_filter_gathers_everything_that_touched_the_patient(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Gestora']);
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $other = Patient::factory()->create();
        $treatment = Treatment::factory()->create(['patient_id' => $patient->id]);
        $audit = app(AuditLogger::class);

        $this->actingAs($admin);
        $audit->log(AuditAction::PatientViewed, $patient);
        $audit->log(AuditAction::TreatmentViewed, $treatment, ['patient_id' => $patient->id]);
        $audit->log(AuditAction::PatientViewed, $other);

        $response = $this->get("/auditoria?paciente={$patient->id}")->assertOk()
            ->assertSee('Marina Albuquerque')
            ->assertSee('Abriu a ficha do paciente')
            ->assertSee('Abriu atendimento');

        $this->assertSame(2, $response->viewData('logs')->total());

        // Paciente arquivado continua consultável na auditoria.
        $patient->delete();
        $this->get("/auditoria?paciente={$patient->id}")->assertOk()->assertSee('Marina Albuquerque');

        // Na trilha da consulta entram só os filtros usados, nunca o que foi buscado.
        $this->assertEquals(['filters' => ['paciente']], AuditLog::where('action', AuditAction::AuditViewed->value)->latest('id')->first()->metadata);
    }

    public function test_audit_list_counts_its_queries(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $audit = app(AuditLogger::class);

        foreach (Patient::factory()->count(5)->create() as $patient) {
            $audit->log(AuditAction::PatientViewed, $patient);
        }

        DB::enableQueryLog();
        $this->get('/auditoria')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Sessão/usuário, permissões, contagem, página, autores, lista de usuários, gravação da própria leitura.
        $this->assertLessThanOrEqual(10, $queries);
    }

    public function test_audit_list_with_varied_entries_keeps_a_fixed_number_of_queries(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $audit = app(AuditLogger::class);

        // Ações, autores e tipos de registro variados, com detalhes traduzidos (AuditDescription).
        $populate = function (int $count) use ($audit): void {
            foreach (range(1, $count) as $_) {
                $author = User::factory()->professional()->create();
                $patient = Patient::factory()->create();
                $audit->log(AuditAction::PatientViewed, $patient, ['tab' => 'fotos'], $author);
                $audit->log(AuditAction::AppointmentStatusChanged, Appointment::factory()->create(['patient_id' => $patient->id]), ['from' => 'scheduled', 'to' => 'no_show', 'patient_id' => $patient->id], $author);
                $audit->log(AuditAction::FinancialEntryDeleted, null, ['type' => 'income', 'payment_method' => 'pix', 'total' => '10.00', 'patient_id' => $patient->id], $author);
                $audit->log(AuditAction::StockMoved, ProductBatch::factory()->create(), ['type' => 'loss'], $author);
            }
        };

        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $populate(1);
        $count('/auditoria');
        $few = [$count('/auditoria'), $count('/auditoria?de=2026-01-01&ate=2027-12-31')];

        $populate(8);
        $many = [$count('/auditoria'), $count('/auditoria?de=2026-01-01&ate=2027-12-31')];

        $this->assertSame($few, $many);
    }

    public function test_finance_and_permission_reads_are_audited(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/financeiro?mes=2026-10')->assertOk();
        $this->assertEquals(['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'todos', 'status' => 'todas', 'patient_filter' => false], AuditLog::where('action', AuditAction::FinanceViewed->value)->sole()->metadata);

        $this->get('/usuarios/permissoes')->assertOk();
        $this->assertSame(1, AuditLog::where('action', AuditAction::PermissionsViewed->value)->count());
    }

    public function test_the_record_prints_registration_health_form_and_treatments(): void
    {
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        PatientAnamnesis::query()->forceCreate(['patient_id' => $patient->id, 'allergies' => 'Dipirona', 'pregnant_or_lactating' => false]);
        $first = Treatment::factory()->create(['patient_id' => $patient->id, 'description' => 'Primeira aplicação', 'performed_at' => '2026-09-01 15:00:00']);
        Treatment::factory()->create(['patient_id' => $patient->id, 'description' => 'Retoque', 'performed_at' => '2026-09-20 15:00:00']);
        Treatment::factory()->create(['description' => 'De outra paciente']);

        $this->actingAs(User::factory()->professional()->create());

        $this->get("/pacientes/{$patient->id}/prontuario")->assertOk()
            ->assertSee('Marina Albuquerque')->assertSee('Dipirona')
            ->assertSeeInOrder(['Primeira aplicação', 'Retoque'])
            ->assertDontSee('De outra paciente')
            ->assertSee('data-print', false);

        $this->get("/pacientes/{$patient->id}/prontuario?atendimento={$first->id}")->assertOk()
            ->assertSee('Primeira aplicação')->assertDontSee('Retoque');

        // Atendimento de outro paciente não sai pela URL deste.
        $foreign = Treatment::query()->where('patient_id', '!=', $patient->id)->sole();
        $this->get("/pacientes/{$patient->id}/prontuario?atendimento={$foreign->id}")->assertNotFound();

        $logs = AuditLog::where('action', AuditAction::PatientRecordPrinted->value)->orderBy('id')->get();
        $this->assertSame([2, 1], $logs->map(fn (AuditLog $log) => $log->metadata['treatments'])->all());
        $this->assertSame($first->id, $logs[1]->metadata['treatment_id']);
    }

    public function test_reception_cannot_print_the_clinical_record(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->create())->get("/pacientes/{$patient->id}/prontuario")->assertForbidden();

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView, Permission::RecordsView]);
        $this->actingAs(User::factory()->create())->get("/pacientes/{$patient->id}/prontuario")->assertOk();
    }

    public function test_patient_page_links_to_print_and_to_the_access_trail(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->admin()->create())->get("/pacientes/{$patient->id}")->assertOk()
            ->assertSee(route('patients.record', $patient))
            ->assertSee(e(route('audit.index', ['paciente' => $patient->id])), false);

        $this->actingAs(User::factory()->professional()->create())->get("/pacientes/{$patient->id}")->assertOk()
            ->assertSee(route('patients.record', $patient))
            ->assertDontSee('Ver acessos');

        // A agenda do dia no Início também é leitura auditada.
        Appointment::factory()->at('2026-10-08 15:00')->create(['patient_id' => $patient->id]);
        // Quem atende abre só a própria agenda: o filtro entra na auditoria (só o id).
        $this->get('/')->assertOk();
        $this->assertEquals(['view' => 'home', 'professional_id' => auth()->id()], AuditLog::where('action', AuditAction::AgendaViewed->value)->latest('id')->first()->metadata);
    }

    public function test_audit_filters_by_user_action_and_period(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Gestora']);
        $helena = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $patient = Patient::factory()->create();
        $audit = app(AuditLogger::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'America/Sao_Paulo'));
        $audit->log(AuditAction::PatientViewed, $patient, actor: $helena);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'America/Sao_Paulo'));
        $audit->log(AuditAction::PatientUpdated, $patient, ['fields' => ['phone']], $helena);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $audit->log(AuditAction::PatientViewed, $patient, actor: $admin);
        $this->actingAs($admin);

        $total = fn (string $query): int => $this->get('/auditoria?'.$query)->assertOk()->viewData('logs')->total();

        $this->assertSame(2, $total("usuario={$helena->id}"));
        $this->assertSame(2, $total('acao='.AuditAction::PatientViewed->value));
        $this->assertSame(1, $total("usuario={$helena->id}&acao=".AuditAction::PatientViewed->value));
        // Período em dias do calendário da clínica, com as duas pontas incluídas.
        $this->assertSame(1, $total('de=2026-10-05&ate=2026-10-05'));
        $this->assertSame(2, $total("de=2026-10-01&ate=2026-10-05&usuario={$helena->id}"));
        $this->assertSame(0, $total('de=2026-10-02&ate=2026-10-04'));

        $this->assertEquals(['filters' => ['usuario', 'acao']], AuditLog::where('action', AuditAction::AuditViewed->value)->orderBy('id')->skip(2)->first()->metadata);
    }

    public function test_audit_filters_are_validated(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/auditoria?acao=qualquer')->assertSessionHasErrors('acao');
        $this->get('/auditoria?usuario=abc')->assertSessionHasErrors('usuario');
        $this->get('/auditoria?paciente=abc')->assertSessionHasErrors('paciente');
        $this->get('/auditoria?de=08/10/2026')->assertSessionHasErrors('de');
        $this->get('/auditoria?de=2026-10-08&ate=2026-10-01')->assertSessionHasErrors('ate');

        // Filtro inválido não chega a consultar a trilha.
        $this->assertSame(0, AuditLog::where('action', AuditAction::AuditViewed->value)->count());
    }

    public function test_the_record_requires_login_and_the_administrator_reads_it(): void
    {
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $url = "/pacientes/{$patient->id}/prontuario";

        $this->get($url)->assertRedirect('/login');
        $this->assertSame(0, AuditLog::where('action', AuditAction::PatientRecordPrinted->value)->count());

        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()
            ->assertSee('Marina Albuquerque')->assertSee('Nenhum atendimento registrado.');

        $this->get("{$url}?atendimento=abc")->assertSessionHasErrors('atendimento');
        $this->get('/pacientes/999999/prontuario')->assertNotFound();
    }

    public function test_the_record_of_an_archived_patient_needs_the_archive_permission(): void
    {
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        Treatment::factory()->create(['patient_id' => $patient->id, 'description' => 'Aplicação antiga']);
        $patient->delete();
        $url = "/pacientes/{$patient->id}/prontuario";

        // Vê prontuário, mas não pacientes arquivados.
        $this->actingAs(User::factory()->professional()->create())->get($url)->assertForbidden();

        app(RolePermissions::class)->sync(UserRole::Professional, [...Permission::defaultsFor(UserRole::Professional), Permission::PatientsArchive]);
        $this->actingAs(User::factory()->professional()->create())->get($url)->assertOk()
            ->assertSee('(cadastro arquivado)')->assertSee('Aplicação antiga');

        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk();

        // Recepção, mesmo podendo arquivar, não vê o prontuário.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsArchive]);
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

        $this->assertSame(2, AuditLog::where('action', AuditAction::PatientRecordPrinted->value)->count());
    }

    public function test_the_record_runs_a_fixed_number_of_queries(): void
    {
        $professional = User::factory()->professional()->create();
        $patient = Patient::factory()->create();
        PatientAnamnesis::query()->forceCreate(['patient_id' => $patient->id, 'allergies' => 'Nenhuma', 'pregnant_or_lactating' => false]);
        $batch = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Ácido hialurônico'])->id, 'batch_code' => 'AH1', 'quantity' => 100, 'expires_on' => '2027-06-30',
        ]);

        $record = function (int $number) use ($professional, $patient, $batch): void {
            $this->actingAs($professional)->post("/pacientes/{$patient->id}/atendimentos", [
                'performed_at' => '2026-10-0'.$number.'T10:00',
                'procedure_id' => $this->procedure("Procedimento {$number}")->id,
                'description' => "Registro {$number}.",
                'products' => [['product_batch_id' => $batch->id, 'quantity' => '1']],
                'amount' => '100,00',
                'payment_method' => PaymentMethod::Pix->value,
                'installments' => 1,
                'first_due_date' => '2026-10-08',
            ])->assertSessionHasNoErrors();

            $treatment = Treatment::query()->latest('id')->firstOrFail();
            $this->post("/pacientes/{$patient->id}/atendimentos/{$treatment->id}/evolucoes", ['body' => "Evolução {$number}."])->assertSessionHasNoErrors();
        };

        $count = function () use ($professional, $patient): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($professional)->get("/pacientes/{$patient->id}/prontuario")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $record(1);
        $count();
        $one = $count();

        foreach ([2, 3, 4, 5] as $number) {
            $record($number);
        }

        $response = $this->actingAs($professional)->get("/pacientes/{$patient->id}/prontuario")->assertOk();
        $response->assertSee('Atendimentos (5)')->assertSee('Evolução 5.')->assertSee('AH1');

        // Mais atendimentos, produtos e evoluções não aumentam o número de consultas.
        $this->assertSame($one, $count());
    }

    public function test_the_record_marks_voided_treatments(): void
    {
        $patient = Patient::factory()->create();
        $treatment = Treatment::factory()->create(['patient_id' => $patient->id, 'description' => 'Registro errado']);
        $treatment->forceFill(['voided_at' => now(), 'voided_by' => User::factory()->admin()->create(['name' => 'Gestora'])->id, 'void_reason' => 'Lançado em duplicidade.'])->save();

        $this->actingAs(User::factory()->professional()->create())->get("/pacientes/{$patient->id}/prontuario")->assertOk()
            ->assertSee('Registro errado')->assertSee('Anulado em 08/10/2026')->assertSee('por Gestora')->assertSee('Lançado em duplicidade.');
    }
}
