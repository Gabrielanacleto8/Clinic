<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\FinancialInstallment;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Services\TreatmentVoider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Anulação de atendimento registrado com erro: desfaz estoque, financeiro e
 * agenda, e mantém o registro no prontuário marcado como anulado.
 */
class TreatmentVoidTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private User $admin;

    private Patient $patient;

    private ProductBatch $toxin;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create();
        $this->admin = User::factory()->admin()->create(['name' => 'Gestora']);
        $this->patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $this->toxin = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Toxina botulínica 100 U'])->id, 'batch_code' => 'C1234', 'quantity' => 100, 'expires_on' => '2027-06-30',
        ]);
        $this->appointment = Appointment::factory()->at('2026-10-08 11:00')->create(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id]);
    }

    private function record(): Treatment
    {
        $this->actingAs($this->professional)->post("/pacientes/{$this->patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T11:30',
            'procedure_id' => $this->procedure('Toxina botulínica', ['price' => 1200])->id,
            'description' => 'Aplicados 30 U.',
            'products' => [['product_batch_id' => $this->toxin->id, 'quantity' => '30']],
            'amount' => '1.200,00',
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 2,
            'first_due_date' => '2026-10-08',
            'appointment_id' => $this->appointment->id,
        ])->assertSessionHasNoErrors();

        return Treatment::query()->latest('id')->firstOrFail();
    }

    private function voidUrl(Treatment $treatment): string
    {
        return "/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}/anulacao";
    }

    public function test_voiding_undoes_stock_finance_and_agenda_and_keeps_the_record(): void
    {
        $treatment = $this->record();
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $entryId = FinancialEntry::sole()->id;

        $this->actingAs($this->admin)
            ->post($this->voidUrl($treatment), ['reason' => 'Registrado na paciente errada.'])
            ->assertRedirect(route('patients.treatments.show', [$this->patient, $treatment]))
            ->assertSessionHasNoErrors();

        $treatment->refresh();
        $this->assertTrue($treatment->isVoided());
        $this->assertSame([$this->admin->id, 'Registrado na paciente errada.'], [$treatment->voided_by, $treatment->void_reason]);

        // Estoque devolvido ao mesmo lote, por movimentação de estorno.
        $this->assertSame('100.000', $this->toxin->refresh()->quantity);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Reversal->value)->where('treatment_id', $treatment->id)->count());

        // Cobrança excluída com as parcelas.
        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame(0, FinancialInstallment::count());

        // Agendamento de volta à agenda, pronto para o registro certo.
        $this->assertSame([AppointmentStatus::Scheduled, null], [$this->appointment->refresh()->status, $this->appointment->treatment_id]);
        $this->assertTrue($this->appointment->isOpenForTreatment());

        // Auditoria só com identificadores: o motivo fica no registro, criptografado.
        $log = AuditLog::where('action', AuditAction::TreatmentVoided->value)->sole();
        $this->assertEquals(['patient_id' => $this->patient->id, 'stock_movements_reversed' => 1, 'financial_entry_id' => $entryId, 'appointment_id' => $this->appointment->id], $log->metadata);
        $this->assertStringNotContainsString('paciente errada', (string) json_encode(AuditLog::all()->pluck('metadata')));
        $this->assertNotSame('Registrado na paciente errada.', DB::table('treatments')->where('id', $treatment->id)->value('void_reason'));

        // O registro continua no prontuário, marcado.
        $this->get("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}")->assertOk()
            ->assertSee('Registro anulado')->assertSee('Registrado na paciente errada.')->assertDontSee('Anular registro');
        $this->get("/pacientes/{$this->patient->id}?aba=atendimentos")->assertOk()->assertSee('Anulado');
        $this->get("/pacientes/{$this->patient->id}?aba=produtos")->assertOk()->assertDontSee('C1234');

        // O registro certo pode ser feito no mesmo agendamento.
        $this->record();
        $this->assertSame(AppointmentStatus::Completed, $this->appointment->refresh()->status);
    }

    public function test_a_treatment_with_a_registered_payment_is_not_voided(): void
    {
        $treatment = $this->record();
        FinancialEntry::sole()->installments()->orderBy('number')->first()->update(['paid_at' => '2026-10-08']);

        $this->actingAs($this->admin)
            ->post($this->voidUrl($treatment), ['reason' => 'Valor digitado errado.'])
            ->assertSessionHasErrors(['reason' => TreatmentVoider::PAID_MESSAGE]);

        $this->assertFalse($treatment->refresh()->isVoided());
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame(1, FinancialEntry::count());
    }

    public function test_only_who_has_the_void_permission_voids_and_only_once(): void
    {
        $treatment = $this->record();

        // Profissional, por padrão, não anula.
        $this->actingAs($this->professional)
            ->get("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}")->assertOk()->assertDontSee('Anular registro');
        $this->post($this->voidUrl($treatment), ['reason' => 'Registrado errado.'])->assertForbidden();

        app(RolePermissions::class)->sync(UserRole::Professional, [...Permission::defaultsFor(UserRole::Professional), Permission::RecordsVoid]);
        $this->actingAs($this->professional->fresh())
            ->get("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}")->assertSee('Anular registro');

        $this->post($this->voidUrl($treatment), ['reason' => 'curto'])->assertSessionHasErrors('reason');
        $this->post($this->voidUrl($treatment), ['reason' => 'Registrado errado.'])->assertSessionHasNoErrors();

        // Anulado não se anula de novo.
        $this->post($this->voidUrl($treatment), ['reason' => 'Registrado errado.'])->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', AuditAction::TreatmentVoided->value)->count());
    }

    public function test_a_treatment_of_another_patient_is_not_reachable(): void
    {
        $treatment = $this->record();
        $other = Patient::factory()->create();

        $this->actingAs($this->admin)
            ->post("/pacientes/{$other->id}/atendimentos/{$treatment->id}/anulacao", ['reason' => 'Registrado errado.'])
            ->assertNotFound();

        $this->assertFalse($treatment->refresh()->isVoided());
    }

    public function test_voided_treatments_leave_the_home_count(): void
    {
        $treatment = $this->record();

        $this->actingAs($this->admin);
        $before = $this->get('/')->viewData('totals');
        $this->post($this->voidUrl($treatment), ['reason' => 'Registrado em duplicidade.'])->assertSessionHasNoErrors();
        $after = $this->get('/')->viewData('totals');

        $this->assertSame($before['treatments'] - 1, $after['treatments']);
    }

    public function test_the_typed_reason_stays_only_in_the_encrypted_record(): void
    {
        $treatment = $this->record();
        $reason = 'Paciente relatou alergia grave. '.str_repeat('Detalhe clínico. ', 57);
        $this->assertGreaterThan(990, mb_strlen($reason));

        $this->actingAs($this->admin)->post($this->voidUrl($treatment), ['reason' => mb_substr($reason, 0, 1000)])->assertSessionHasNoErrors();

        // No estoque, visível a quem não vê o prontuário, vai só a referência ao atendimento.
        $reversal = StockMovement::where('type', StockMovementType::Reversal->value)->sole();
        $this->assertSame("Estorno pela anulação do atendimento nº {$treatment->id}", $reversal->reason);
        $this->assertSame($this->patient->id, AuditLog::where('action', AuditAction::StockMoved->value)->latest('id')->first()->metadata['patient_id']);
    }

    public function test_the_void_permission_brings_the_finance_permission(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::RecordsVoid]);

        $granted = app(RolePermissions::class)->for(UserRole::Receptionist);

        $this->assertContains(Permission::FinanceManage, $granted);
        $this->assertContains(Permission::RecordsManage, $granted);
    }

    public function test_a_guest_is_sent_to_login_and_nothing_changes(): void
    {
        $treatment = $this->record();
        auth()->logout();

        $this->post($this->voidUrl($treatment), ['reason' => 'Registrado errado.'])->assertRedirect('/login');

        $this->assertFalse($treatment->refresh()->isVoided());
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame(1, FinancialEntry::count());
    }

    public function test_the_reason_is_required_and_has_limits(): void
    {
        $treatment = $this->record();
        $this->actingAs($this->admin);

        $this->post($this->voidUrl($treatment), [])->assertSessionHasErrors('reason');
        $this->post($this->voidUrl($treatment), ['reason' => '   '])->assertSessionHasErrors('reason');
        $this->post($this->voidUrl($treatment), ['reason' => str_repeat('a', 1001)])->assertSessionHasErrors('reason');

        $this->assertFalse($treatment->refresh()->isVoided());
        $this->assertSame(0, AuditLog::where('action', AuditAction::TreatmentVoided->value)->count());
    }

    public function test_a_treatment_of_an_archived_patient_is_not_reachable(): void
    {
        $treatment = $this->record();
        $this->patient->delete();

        $this->actingAs($this->admin)->post($this->voidUrl($treatment), ['reason' => 'Registrado errado.'])->assertNotFound();

        $this->assertFalse($treatment->refresh()->isVoided());
    }

    public function test_when_the_time_was_taken_meanwhile_the_appointment_stays_cancelled(): void
    {
        $treatment = $this->record();
        $other = Appointment::factory()->at('2026-10-08 11:00')->create(['professional_id' => $this->professional->id]);

        $this->actingAs($this->admin)->post($this->voidUrl($treatment), ['reason' => 'Registrado errado.'])->assertSessionHasNoErrors();

        $this->assertTrue($treatment->refresh()->isVoided());
        $this->assertSame([AppointmentStatus::Cancelled, null], [$this->appointment->refresh()->status, $this->appointment->treatment_id]);
        $this->assertSame(AppointmentStatus::Scheduled, $other->refresh()->status);

        $log = AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->where('auditable_id', $this->appointment->id)->latest('id')->first();
        $this->assertEquals(['patient_id' => $this->patient->id, 'from' => 'completed', 'to' => 'cancelled', 'reason' => 'treatment_voided'], $log->metadata);
    }

    public function test_the_treatment_list_and_summary_show_the_void(): void
    {
        $treatment = $this->record();
        $this->actingAs($this->admin)->post($this->voidUrl($treatment), ['reason' => 'Registrado em duplicidade.'])->assertSessionHasNoErrors();

        // A lista mostra só os válidos por padrão; os anulados entram pelo filtro.
        $this->actingAs($this->professional)->get('/atendimentos')->assertOk()->assertDontSee('Anulado');
        $this->get('/atendimentos?situacao=todos')->assertOk()->assertSee('Anulado');
        $this->get("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}/resumo")->assertOk()
            ->assertSee('Registro anulado em 08/10/2026')->assertSee('Registrado em duplicidade.');
    }
}
