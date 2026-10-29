<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Janela de resumo da lista de atendimentos: clicar em um atendimento mostra
 * o registro sem sair da lista.
 */
class TreatmentSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create(['name' => 'Marina Duarte']);
    }

    /**
     * Registra um atendimento completo: produto, cobrança e uma evolução.
     */
    private function record(?Patient $patient = null): Treatment
    {
        $patient ??= $this->patient;
        $batch = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Toxina botulínica 100 U'])->id, 'batch_code' => 'C1234', 'quantity' => 100, 'expires_on' => '2027-06-30',
        ]);

        $this->actingAs($this->professional)->post("/pacientes/{$patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->procedure('Toxina botulínica')->id,
            'treated_areas' => 'Glabela',
            'description' => 'Aplicados 20 U na glabela.',
            'post_care' => 'Não deitar por 4 horas.',
            'products' => [['product_batch_id' => $batch->id, 'quantity' => '20']],
            'amount' => '1.200,00',
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            'first_installment_paid' => '1',
        ])->assertSessionHasNoErrors();

        $treatment = Treatment::query()->latest('id')->firstOrFail();

        $this->post("/pacientes/{$patient->id}/atendimentos/{$treatment->id}/evolucoes", ['body' => 'Retorno sem queixas.'])->assertSessionHasNoErrors();

        return $treatment;
    }

    private function summaryUrl(Treatment $treatment, ?Patient $patient = null): string
    {
        return '/pacientes/'.($patient ?? $this->patient)->id."/atendimentos/{$treatment->id}/resumo";
    }

    public function test_the_list_opens_each_treatment_in_a_dialog(): void
    {
        $treatment = $this->record();

        $this->actingAs($this->professional)->get('/atendimentos')
            ->assertOk()
            ->assertSee('<dialog id="treatment-summary"', false)
            ->assertSee('data-summary="'.route('patients.treatments.summary', [$this->patient, $treatment]).'" data-summary-dialog="treatment-summary"', false)
            // Sem JavaScript, o procedimento continua levando à página completa.
            ->assertSee('href="'.route('patients.treatments.show', [$this->patient, $treatment]).'"', false);
    }

    public function test_the_summary_shows_the_record_and_is_audited(): void
    {
        $treatment = $this->record();

        $this->actingAs($this->professional)->get($this->summaryUrl($treatment))
            ->assertOk()
            ->assertSee('data-summary-content', false)
            ->assertDontSee('<html', false)
            ->assertSeeInOrder(['Toxina botulínica', 'Marina Duarte', '08/10/2026 às 12:00', 'Dra. Helena'])
            ->assertSee('Aplicados 20 U na glabela.')
            ->assertSee('Não deitar por 4 horas.')
            ->assertSee('C1234')
            ->assertSee('Retorno sem queixas.')
            ->assertSee('Abrir página completa')
            // O profissional não vê o financeiro.
            ->assertDontSee('R$ 1.200,00');

        $audit = AuditLog::where('action', AuditAction::TreatmentViewed->value)->latest('id')->firstOrFail();
        $this->assertSame($treatment->id, $audit->auditable_id);
        $this->assertEquals(['patient_id' => $this->patient->id, 'view' => 'summary'], $audit->metadata);

        // Quem vê o financeiro vê a cobrança.
        $this->actingAs(User::factory()->admin()->create())->get($this->summaryUrl($treatment))
            ->assertOk()
            ->assertSee('R$ 1.200,00');
    }

    public function test_access_follows_the_clinical_records_permission(): void
    {
        $treatment = $this->record();
        $other = Patient::factory()->create();

        auth()->logout();
        $this->get($this->summaryUrl($treatment))->assertRedirect('/login');

        // Recepção não vê prontuário.
        $this->actingAs(User::factory()->create())->get($this->summaryUrl($treatment))->assertForbidden();

        // Atendimento pelo paciente errado não é alcançado.
        $this->actingAs($this->professional)->get($this->summaryUrl($treatment, $other))->assertNotFound();

        $this->assertSame(0, AuditLog::where('action', AuditAction::TreatmentViewed->value)->count());
    }

    public function test_the_summary_runs_a_fixed_number_of_queries(): void
    {
        $count = function (Treatment $treatment): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->professional)->get($this->summaryUrl($treatment))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $first = $this->record();
        $count($first);
        $one = $count($first);

        // Mais evoluções não aumentam o número de consultas.
        foreach (range(1, 4) as $number) {
            $this->post("/pacientes/{$this->patient->id}/atendimentos/{$first->id}/evolucoes", ['body' => "Evolução {$number}."]);
        }

        $this->assertSame($one, $count($first));
    }
}
