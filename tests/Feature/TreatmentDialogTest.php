<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Diálogo "Registrar atendimento" do Início e da lista de atendimentos, em que
 * o paciente é escolhido no próprio formulário.
 */
class TreatmentDialogTest extends TestCase
{
    use RefreshDatabase;

    private const PICK_MESSAGE = 'Busque e selecione um paciente na lista.';

    private User $professional;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create();
        $this->patient = Patient::factory()->create(['name' => 'Marina Duarte']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            '_dialog' => 'new-treatment',
            'patient_id' => $this->patient->id,
            'performed_at' => '2026-10-08T12:40',
            'procedure_id' => $this->procedure('Toxina botulínica')->id,
            'treated_areas' => 'Glabela',
            'description' => 'Aplicados 20 U na glabela.',
            'amount' => '0',
            ...$overrides,
        ];
    }

    public function test_clinical_staff_get_the_treatment_form_in_a_dialog_on_home_and_on_the_list(): void
    {
        $batch = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Toxina botulínica 100 U'])->id,
            'batch_code' => 'C1234',
            'quantity' => 10,
            'expires_on' => '2027-06-30',
        ]);
        $this->procedure('Toxina botulínica');

        foreach (['/', '/atendimentos'] as $page) {
            $this->actingAs($this->professional)->get($page)
                ->assertOk()
                ->assertSee('data-dialog-open="new-treatment"', false)
                ->assertSee('<dialog id="new-treatment"', false)
                ->assertSee('<form method="POST" action="'.route('treatments.store').'"', false)
                ->assertSee('data-confirm="Registrar o atendimento?', false)
                ->assertSee('data-patient-picker', false)
                ->assertSee('name="performed_at"', false)
                ->assertSee('name="description"', false)
                ->assertSee('name="amount"', false)
                ->assertSee('name="payment_method"', false)
                ->assertSee('<option value="'.$batch->id.'"', false)
                ->assertSee('Toxina botulínica')
                // Só o administrador escolhe o profissional responsável.
                ->assertDontSee('name="professional_id"', false)
                ->assertDontSee('data-dialog-autoshow', false);
        }

        $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertSee('<dialog id="new-treatment"', false)
            ->assertSee('name="professional_id"', false);
    }

    public function test_reception_gets_neither_the_button_nor_the_form(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk()
            ->assertSee('Novo agendamento')
            ->assertDontSee('Registrar atendimento')
            ->assertDontSee('new-treatment', false);
        $this->assertNull($response->viewData('treatmentForm'));
    }

    public function test_the_dialog_registers_the_treatment_for_the_chosen_patient(): void
    {
        $this->actingAs($this->professional);

        $batch = ProductBatch::factory()->create(['product_id' => Product::factory()->create()->id, 'quantity' => 10, 'expires_on' => '2027-06-30']);

        $response = $this->from('/')->post('/atendimentos', $this->payload(['products' => [['product_batch_id' => $batch->id, 'quantity' => '2,5']]]))
            ->assertSessionHasNoErrors();

        $treatment = Treatment::sole();

        $response->assertRedirect("/pacientes/{$this->patient->id}/atendimentos/{$treatment->id}");

        $this->assertSame($this->patient->id, $treatment->patient_id);
        $this->assertSame($this->professional->id, $treatment->professional_id);
        $this->assertSame($this->professional->id, $treatment->created_by);
        $this->assertSame('2026-10-08 15:40:00', $treatment->performed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Aplicados 20 U na glabela.', $treatment->description);
        // Registrado pelo diálogo, o estoque é baixado como em qualquer registro.
        $this->assertSame('7.500', $batch->refresh()->quantity);
        $this->assertSame($treatment->id, StockMovement::sole()->treatment_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::TreatmentCreated->value, 'user_id' => $this->professional->id, 'auditable_id' => $treatment->id,
        ]);
    }

    public function test_guests_and_reception_cannot_register_a_treatment_from_home(): void
    {
        $this->post('/atendimentos', $this->payload())->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/atendimentos', $this->payload())->assertForbidden();

        $this->assertSame(0, Treatment::count());
    }

    public function test_patient_must_be_chosen_from_the_list(): void
    {
        $archived = Patient::factory()->create();
        $archived->delete();

        $this->actingAs($this->professional);

        foreach (['', 'abc', '999999', (string) $archived->id] as $patientId) {
            $this->from('/')->post('/atendimentos', $this->payload(['patient_id' => $patientId]))
                ->assertRedirect('/')
                ->assertSessionHasErrors(['patient_id' => self::PICK_MESSAGE]);
        }

        $this->assertSame(0, Treatment::count());
    }

    public function test_a_treatment_from_the_dialog_cannot_be_tied_to_an_appointment(): void
    {
        $appointment = Appointment::factory()->at('2026-10-08 12:30')->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->professional)
            ->post('/atendimentos', $this->payload(['appointment_id' => $appointment->id]))
            ->assertSessionHasErrors('appointment_id');

        $this->assertSame(0, Treatment::count());
        $this->assertNull($appointment->refresh()->treatment_id);
    }

    public function test_invalid_data_reopens_the_dialog_keeping_the_patient_and_what_was_typed(): void
    {
        $this->actingAs($this->professional);

        $this->followingRedirects()->from('/')
            ->post('/atendimentos', $this->payload(['description' => '', 'treated_areas' => 'Glabela e frontal']))
            ->assertOk()
            ->assertSee('data-dialog-autoshow', false)
            ->assertSee('O campo descrição é obrigatório.')
            ->assertSee('name="patient_id" value="'.$this->patient->id.'"', false)
            ->assertSee('value="Marina Duarte"', false)
            ->assertSee('value="Glabela e frontal"', false);

        $this->assertSame(0, Treatment::count());

        $this->followingRedirects()->from('/')->post('/atendimentos', $this->payload(['patient_id' => '']))
            ->assertSee('data-dialog-autoshow', false)
            ->assertSee(self::PICK_MESSAGE);
    }

    public function test_health_alerts_of_the_chosen_patient_are_served_to_clinical_staff_and_audited(): void
    {
        $this->patient->anamnesis()->create(['allergies' => 'Lidocaína', 'pregnant_or_lactating' => true]);
        $withoutRecord = Patient::factory()->create();

        $this->actingAs($this->professional);

        $this->getJson("/pacientes/{$this->patient->id}/alertas-de-saude")
            ->assertOk()
            ->assertExactJson(['missing' => false, 'stale_since' => null, 'alerts' => [
                ['label' => null, 'text' => 'Gestante ou lactante.'],
                ['label' => 'Alergias', 'text' => 'Lidocaína'],
            ]]);

        $this->getJson("/pacientes/{$withoutRecord->id}/alertas-de-saude")
            ->assertOk()
            ->assertExactJson(['missing' => true, 'stale_since' => null, 'alerts' => []]);

        $log = AuditLog::where('action', AuditAction::PatientViewed->value)->where('auditable_id', $this->patient->id)->sole();
        $this->assertSame($this->professional->id, $log->user_id);
        $this->assertSame(['context' => 'health_alerts'], $log->metadata);
    }

    public function test_health_alerts_are_denied_to_guests_and_reception(): void
    {
        $this->patient->anamnesis()->create(['allergies' => 'Lidocaína']);
        $url = "/pacientes/{$this->patient->id}/alertas-de-saude";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden()->assertDontSee('Lidocaína');

        $this->assertSame(0, AuditLog::where('action', AuditAction::PatientViewed->value)->count());
    }

    public function test_the_dialog_adds_a_fixed_number_of_queries_to_home(): void
    {
        $count = function (User $user): int {
            $this->actingAs($user);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $admin = User::factory()->admin()->create();
        $seed = function (int $number): void {
            $this->procedure("Procedimento {$number}");
            ProductBatch::factory()->create(['product_id' => Product::factory()->create()->id, 'quantity' => 5, 'expires_on' => '2027-06-30']);
        };

        $seed(1);
        // A primeira requisição do processo faz uma consulta a mais, que não se repete.
        $count($admin);
        $withOneOfEach = $count($admin);

        foreach (range(2, 7) as $number) {
            $seed($number);
        }

        // Mais procedimentos, produtos e lotes não mudam o número de consultas.
        $this->assertSame($withOneOfEach, $count($admin));
        // Quem não é administrador não recebe a lista de profissionais.
        $this->assertCount(0, $this->actingAs($this->professional)->get('/')->viewData('treatmentForm')['professionals']);
        $this->assertNotCount(0, $this->actingAs($admin)->get('/')->viewData('treatmentForm')['professionals']);
    }
}
