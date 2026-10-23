<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ProcedureCategory;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FinancialRecorder;
use App\Services\RolePermissions;
use App\Support\AuditDescription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Procedimentos, usuários, permissões e auditoria: avisos, dependências e trilha legível.
 */
class AdminRefinementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create(['name' => 'Gestora']);
    }

    /**
     * @return array<string, mixed>
     */
    private function procedurePayload(Procedure $procedure, array $overrides = []): array
    {
        return [
            'name' => $procedure->name,
            'category' => ($procedure->category ?? ProcedureCategory::Injectable)->value,
            'price' => '1.200,00',
            'duration_minutes' => 30,
            'is_active' => '1',
            ...$overrides,
        ];
    }

    // --- Procedimentos ----------------------------------------------------

    public function test_a_deactivated_procedure_still_registers_the_appointments_already_booked(): void
    {
        $procedure = Procedure::factory()->create(['name' => 'Peeling antigo', 'price' => 400]);
        $professional = User::factory()->professional()->create();
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->at('2026-10-08 10:00')->create(['patient_id' => $patient->id, 'professional_id' => $professional->id, 'procedure_id' => $procedure->id]);
        Appointment::factory()->at('2026-10-15 10:00')->create(['procedure_id' => $procedure->id]);
        Appointment::factory()->at('2026-10-01 10:00')->status(AppointmentStatus::Completed)->create(['procedure_id' => $procedure->id]);

        $this->actingAs($this->admin);
        $this->get("/procedimentos/{$procedure->id}/editar")->assertOk()->assertSee('Há 2 agendamentos em aberto com este procedimento');

        $this->put("/procedimentos/{$procedure->id}", $this->procedurePayload($procedure, ['is_active' => '0']))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'Há 2 agendamentos em aberto'));
        $this->assertFalse($procedure->refresh()->is_active);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);

        // No registro, o procedimento do agendamento continua na lista e é aceito.
        $this->actingAs($professional);
        $this->get("/pacientes/{$patient->id}/atendimentos/novo?agendamento={$appointment->id}")->assertOk()->assertSee('Peeling antigo');
        $this->get("/pacientes/{$patient->id}/atendimentos/novo")->assertOk()->assertDontSee('Peeling antigo');

        $this->post("/pacientes/{$patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T10:30', 'procedure_id' => $procedure->id, 'description' => 'Feito como agendado.',
            'amount' => '0', 'first_installment_paid' => '0', 'appointment_id' => $appointment->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);

        // Sem o agendamento, o procedimento inativo continua recusado.
        $this->post("/pacientes/{$patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T11:30', 'procedure_id' => $procedure->id, 'description' => 'Sem agendamento.', 'amount' => '0', 'first_installment_paid' => '0',
        ])->assertSessionHasErrors('procedure_id');
    }

    public function test_procedure_changes_are_audited_with_the_price_before_and_after(): void
    {
        $this->actingAs($this->admin);
        $product = Product::factory()->create();

        $this->post('/procedimentos', $this->procedurePayload(new Procedure(['name' => 'Toxina']), ['price' => '900,00']))->assertSessionHasNoErrors();
        $procedure = Procedure::sole();
        $this->assertEquals(['price' => '900.00'], AuditLog::where('action', AuditAction::ProcedureCreated->value)->sole()->metadata);

        $this->put("/procedimentos/{$procedure->id}", $this->procedurePayload($procedure, ['price' => '1.100,00', 'products' => [['product_id' => $product->id, 'quantity' => '1']]]))->assertSessionHasNoErrors();
        $this->assertEquals(
            ['fields' => ['price', 'products'], 'price_from' => '900.00', 'price_to' => '1100.00'],
            AuditLog::where('action', AuditAction::ProcedureUpdated->value)->sole()->metadata,
        );

        // Salvar sem mudar nada não gera registro.
        $this->put("/procedimentos/{$procedure->id}", $this->procedurePayload($procedure->refresh(), ['price' => '1.100,00', 'products' => [['product_id' => $product->id, 'quantity' => '1']]]))->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', AuditAction::ProcedureUpdated->value)->count());
    }

    public function test_procedure_form_has_the_add_product_button(): void
    {
        Product::factory()->create();

        $this->actingAs($this->admin)->get('/procedimentos/novo')->assertOk()
            ->assertSee('data-rows-add="procedure-products"', false)
            ->assertSee('data-rows-max="10"', false)
            ->assertSee('Adicionar produto');
    }

    // --- Usuários ---------------------------------------------------------

    public function test_deactivating_a_professional_with_future_appointments_warns_and_links_to_the_agenda(): void
    {
        $professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        Appointment::factory()->at('2026-10-09 10:00')->create(['professional_id' => $professional->id]);
        Appointment::factory()->at('2026-10-10 10:00')->create(['professional_id' => $professional->id]);
        Appointment::factory()->at('2026-10-01 10:00')->create(['professional_id' => $professional->id, 'status' => AppointmentStatus::Completed]);
        $payload = ['name' => 'Dra. Helena', 'email' => $professional->email, 'role' => 'professional', 'is_active' => '0'];

        $this->actingAs($this->admin)->put("/usuarios/{$professional->id}", $payload)
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'tem 2 agendamentos em aberto'))
            ->assertSessionHas('warning_link', ['url' => route('agenda.index', ['profissional' => $professional->id]), 'label' => 'Ver na agenda']);
        $this->assertFalse($professional->refresh()->is_active);
        // Nada é cancelado.
        $this->assertSame(2, Appointment::where('professional_id', $professional->id)->whereIn('status', AppointmentStatus::open())->count());

        $this->get('/usuarios')->assertOk()->assertSee('Ver na agenda');

        // Reativar como recepção (sem atender) também avisa; sem agendamento, nada é dito.
        $this->put("/usuarios/{$professional->id}", [...$payload, 'is_active' => '1'])->assertSessionMissing('warning');
        $this->put("/usuarios/{$professional->id}", [...$payload, 'is_active' => '1', 'role' => 'receptionist'])->assertSessionHas('warning');
        $other = User::factory()->professional()->create();
        $this->put("/usuarios/{$other->id}", ['name' => $other->name, 'email' => $other->email, 'role' => 'professional', 'is_active' => '0'])->assertSessionMissing('warning');
    }

    public function test_user_forms_link_back_to_the_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);
        $this->get('/usuarios/novo')->assertOk()->assertSee('← Usuários');
        $this->get("/usuarios/{$user->id}/editar")->assertOk()->assertSee('← Usuários');
    }

    // --- Permissões -------------------------------------------------------

    public function test_permission_boxes_carry_their_requirements_and_the_message_lists_what_was_implied(): void
    {
        $this->actingAs($this->admin);

        $this->get('/usuarios/permissoes')->assertOk()
            ->assertSee('data-requires="records.manage,finance.manage"', false)
            ->assertSee('data-requires-note', false);

        $this->put('/usuarios/permissoes', ['permissions' => [
            UserRole::Receptionist->value => [Permission::RecordsVoid->value],
            UserRole::Professional->value => [Permission::PatientsView->value],
        ]])->assertSessionHas('success', function (string $message) {
            return str_contains($message, 'Incluídas automaticamente por dependência: Recepção:')
                && str_contains($message, Permission::FinanceManage->label())
                && str_contains($message, Permission::RecordsView->label())
                && ! str_contains($message, 'Profissional:');
        });

        $this->assertContains(Permission::FinanceManage, app(RolePermissions::class)->for(UserRole::Receptionist));

        // Salvar de novo o mesmo conjunto não inclui nada.
        $this->put('/usuarios/permissoes', ['permissions' => [
            UserRole::Receptionist->value => array_map(fn (Permission $p) => $p->value, app(RolePermissions::class)->for(UserRole::Receptionist)),
            UserRole::Professional->value => [Permission::PatientsView->value],
        ]])->assertSessionHas('success', 'Permissões atualizadas.');
    }

    // --- Auditoria legível ------------------------------------------------

    public function test_audit_trail_is_shown_in_portuguese_with_labels_and_values(): void
    {
        $patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $entry = app(FinancialRecorder::class)->record(
            category: FinancialCategory::Rent, description: 'Conta de luz', total: '250', paymentMethod: PaymentMethod::BankSlip,
            installments: 2, firstDueDate: '2026-10-10', firstInstallmentPaid: false, createdBy: $this->admin, issuedOn: '2026-10-05',
        );

        $this->actingAs($this->admin);
        $this->delete("/financeiro/lancamentos/{$entry->id}")->assertSessionHasNoErrors();
        app(AuditLogger::class)->log(AuditAction::PatientUpdated, $patient, ['fields' => ['phone', 'email']], $this->admin);
        app(AuditLogger::class)->log(AuditAction::PermissionsUpdated, null, ['role' => 'receptionist', 'added' => ['finance.receive'], 'removed' => []], $this->admin);

        $this->get('/auditoria')->assertOk()
            ->assertSee('Lançamento #'.$entry->id)
            ->assertSee('Tipo:')->assertSee('Despesa')
            ->assertSee('Categoria:')->assertSee('Aluguel e condomínio')
            ->assertSee('Total:')->assertSee('R$ 250,00')
            ->assertSee('Data do lançamento:')->assertSee('05/10/2026')
            ->assertSee('Forma de pagamento:')->assertSee('Boleto')
            ->assertSee('Paciente #'.$patient->id)
            ->assertSee('Campos alterados:')->assertSee('telefone, e-mail')
            ->assertSee('Perfil:')->assertSee('Recepção')
            ->assertSee('Concedidas:')->assertSee(Permission::FinanceReceive->label())
            ->assertDontSee('financial_entry #')
            ->assertDontSee('fields:');

        // Registro com paciente leva à auditoria dele.
        app(AuditLogger::class)->log(AuditAction::TreatmentViewed, null, ['patient_id' => $patient->id], $this->admin);
        $this->get('/auditoria')->assertSee(e(route('audit.index', ['paciente' => $patient->id])), false)->assertSee('Ver tudo deste paciente');
        $this->get("/auditoria?paciente={$patient->id}")->assertOk()->assertDontSee('Ver tudo deste paciente');
    }

    public function test_audit_description_translates_known_values_and_keeps_unknown_ones(): void
    {
        $this->assertSame(
            ['De' => 'Agendado', 'Para' => 'Cancelado', 'Motivo' => 'paciente arquivado', 'xyz' => '1'],
            AuditDescription::details(['from' => 'scheduled', 'to' => 'cancelled', 'reason' => 'patient_archived', 'xyz' => 1]),
        );
        $this->assertSame(
            ['De' => 'Forma de pagamento: Pix; Parcelas: 1; Primeiro vencimento: 08/10/2026', 'Com cobrança' => 'sim'],
            AuditDescription::details(['from' => ['payment_method' => 'pix', 'installments' => 1, 'first_due_date' => '2026-10-08'], 'charged' => true]),
        );
        $this->assertSame([], AuditDescription::details(null));
    }
}
