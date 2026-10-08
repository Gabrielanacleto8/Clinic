<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartTreatmentFromAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    private Appointment $appointment;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create();
        $this->appointment = Appointment::factory()->at('2026-10-08 12:30')->create([
            'patient_id' => $this->patient->id,
            'professional_id' => $this->professional->id,
            'procedure_id' => $this->procedure('Preenchimento labial')->id,
        ]);
        $this->url = "/pacientes/{$this->patient->id}/atendimentos";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-08T12:40',
            'procedure_id' => $this->procedure('Preenchimento labial')->id,
            'description' => 'Aplicado 1 mL de ácido hialurônico.',
            'appointment_id' => $this->appointment->id,
            ...$overrides,
        ];
    }

    public function test_treatment_form_opens_prefilled_from_the_appointment(): void
    {
        $this->actingAs($this->professional)
            ->get("{$this->url}/novo?agendamento={$this->appointment->id}")
            ->assertOk()
            ->assertSee('Atendimento do agendamento de 08/10/2026')
            ->assertSee('12:30')
            ->assertSee('name="appointment_id" value="'.$this->appointment->id.'"', false)
            ->assertSee('selected>Preenchimento labial</option>', false);
    }

    public function test_saving_the_treatment_links_the_appointment_and_finalizing_completes_it(): void
    {
        $this->actingAs($this->professional)->post($this->url, $this->payload())->assertSessionHasNoErrors();

        $treatment = Treatment::sole();
        $this->appointment->refresh();

        // Iniciado: ligado ao atendimento, mas ainda não "atendido".
        $this->assertSame($treatment->id, $this->appointment->treatment_id);
        $this->assertSame(AppointmentStatus::Scheduled, $this->appointment->status);
        $this->assertSame($this->professional->id, $treatment->professional_id);
        $this->assertTrue($treatment->isDraft());

        $treatmentUrl = route('patients.treatments.show', [$this->patient, $treatment]);
        $this->get('/')->assertSee('Continuar atendimento')->assertSee($treatmentUrl, false)->assertDontSee('Iniciar atendimento');

        $this->post("{$this->url}/{$treatment->id}/finalizar", ['amount' => '0'])->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Completed, $this->appointment->refresh()->status);

        $log = AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->sole();
        $this->assertSame(['scheduled', 'completed'], [$log->metadata['from'], $log->metadata['to']]);

        $this->get('/')->assertSee('Atendido')->assertSee('Ver atendimento')->assertDontSee('Continuar atendimento');
    }

    public function test_admin_starting_an_appointment_keeps_its_professional_by_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get("{$this->url}/novo?agendamento={$this->appointment->id}")
            ->assertSee('<option value="'.$this->professional->id.'" selected>Dra. Helena</option>', false);
    }

    public function test_reception_cannot_start_a_treatment(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get("{$this->url}/novo?agendamento={$this->appointment->id}")->assertForbidden();
        $this->post($this->url, $this->payload())->assertForbidden();

        $this->assertTrue($this->appointment->refresh()->isOpen());
    }

    public function test_an_appointment_can_only_be_started_once(): void
    {
        $this->actingAs($this->professional);

        $this->post($this->url, $this->payload())->assertSessionHasNoErrors();
        $this->post($this->url, $this->payload())->assertSessionHasErrors('appointment_id');

        $this->assertSame(1, Treatment::count());
    }

    public function test_appointment_must_belong_to_the_patient_be_open_and_not_be_in_the_future(): void
    {
        $this->actingAs($this->professional);

        $ofAnotherPatient = Appointment::factory()->at('2026-10-08 11:00')->create();
        $cancelled = Appointment::factory()->at('2026-10-08 10:00')->status(AppointmentStatus::Cancelled)->create(['patient_id' => $this->patient->id]);
        $tomorrow = Appointment::factory()->at('2026-10-09 10:00')->create(['patient_id' => $this->patient->id]);

        foreach ([$ofAnotherPatient, $cancelled, $tomorrow] as $appointment) {
            $this->post($this->url, $this->payload(['appointment_id' => $appointment->id]))->assertSessionHasErrors('appointment_id');
        }

        $this->post($this->url, $this->payload(['appointment_id' => 999999]))->assertSessionHasErrors('appointment_id');

        $this->assertSame(0, Treatment::count());
        $this->assertTrue($ofAnotherPatient->refresh()->isOpen());
        $this->assertTrue($tomorrow->refresh()->isOpen());
    }

    public function test_form_ignores_an_appointment_that_is_not_open_for_this_patient(): void
    {
        $other = Appointment::factory()->at('2026-10-08 11:00')->create(['procedure_id' => $this->procedure('Procedimento Alheio')->id]);

        $this->actingAs($this->professional)
            ->get("{$this->url}/novo?agendamento={$other->id}")
            ->assertOk()
            ->assertDontSee('Atendimento do agendamento')
            ->assertDontSee('selected>Procedimento Alheio', false)
            ->assertDontSee('name="appointment_id"', false);
    }

    public function test_treatments_still_work_without_an_appointment_and_edits_cannot_attach_one(): void
    {
        $this->actingAs($this->professional);

        $this->post($this->url, $this->payload(['appointment_id' => null]))->assertSessionHasNoErrors();

        $treatment = Treatment::sole();

        $this->assertTrue($this->appointment->refresh()->isOpen());

        $this->put("{$this->url}/{$treatment->id}", $this->payload())->assertSessionHasErrors('appointment_id');
        $this->assertTrue($this->appointment->refresh()->isOpen());
    }

    public function test_a_past_appointment_left_open_can_still_be_started(): void
    {
        $yesterday = Appointment::factory()->at('2026-10-07 16:00')->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->professional)
            ->post($this->url, $this->payload(['appointment_id' => $yesterday->id, 'performed_at' => '2026-10-07T16:10']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Treatment::sole()->id, $yesterday->refresh()->treatment_id);
    }
}
