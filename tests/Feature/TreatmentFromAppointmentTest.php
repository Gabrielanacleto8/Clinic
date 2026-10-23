<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\TreatmentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TreatmentFromAppointmentTest extends TestCase
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
        // Agendado, sem presença confirmada: não há sala de espera a percorrer.
        $this->appointment = Appointment::factory()->at('2026-10-08 12:30')->create([
            'patient_id' => $this->patient->id,
            'professional_id' => $this->professional->id,
            'procedure_id' => $this->procedure('Preenchimento labial', ['price' => 1800])->id,
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
            'amount' => '0',
            'appointment_id' => $this->appointment->id,
            ...$overrides,
        ];
    }

    public function test_treatment_form_opens_prefilled_from_the_appointment(): void
    {
        $filler = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Ácido hialurônico'])->id, 'batch_code' => 'AH01', 'quantity' => 5, 'expires_on' => '2027-06-30',
        ]);
        $this->appointment->procedure->products()->sync([$filler->product_id => ['quantity' => 1.5]]);

        $this->actingAs($this->professional)
            ->get("{$this->url}/novo?agendamento={$this->appointment->id}")
            ->assertOk()
            ->assertSee('Atendimento do agendamento de 08/10/2026')
            ->assertSee('12:30')
            ->assertSee('name="appointment_id" value="'.$this->appointment->id.'"', false)
            ->assertSee('selected>Preenchimento labial</option>', false)
            // O procedimento já vem escolhido, então o valor e os produtos dele também.
            ->assertSee('value="1800,00"', false)
            ->assertSee('<option value="'.$filler->id.'" data-unit="', false)
            ->assertSee('value="1,5"', false);
    }

    public function test_registering_the_treatment_completes_the_appointment(): void
    {
        $this->actingAs($this->professional);

        $this->get('/?todos=1')->assertSee('Registrar atendimento')->assertSee("agendamento={$this->appointment->id}", false);

        $this->post($this->url, $this->payload())->assertSessionHasNoErrors();

        $treatment = Treatment::sole();
        $this->appointment->refresh();

        $this->assertSame($treatment->id, $this->appointment->treatment_id);
        $this->assertSame(AppointmentStatus::Completed, $this->appointment->status);
        $this->assertSame($this->professional->id, $treatment->professional_id);

        $log = AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->sole();
        $this->assertSame(['scheduled', 'completed'], [$log->metadata['from'], $log->metadata['to']]);

        $treatmentUrl = route('patients.treatments.show', [$this->patient, $treatment]);
        $this->get('/')->assertSee('Atendido')->assertSee('Ver atendimento')->assertSee($treatmentUrl, false)
            ->assertDontSee("agendamento={$this->appointment->id}", false);
    }

    public function test_arrival_confirmation_is_optional(): void
    {
        $confirmed = Appointment::factory()->at('2026-10-08 11:00')->status(AppointmentStatus::Confirmed)->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->professional);

        $this->get('/agenda?profissional=todos')->assertSee("agendamento={$this->appointment->id}", false)->assertSee("agendamento={$confirmed->id}", false);

        $this->post($this->url, $this->payload(['appointment_id' => $confirmed->id]))->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Completed, $confirmed->refresh()->status);
        // O outro agendamento do paciente segue em aberto.
        $this->assertTrue($this->appointment->refresh()->isOpen());
    }

    public function test_admin_registering_from_an_appointment_keeps_its_professional_by_default(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get("{$this->url}/novo?agendamento={$this->appointment->id}")
            ->assertSee('<option value="'.$this->professional->id.'" selected>Dra. Helena</option>', false);
    }

    public function test_reception_cannot_register_a_treatment(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/agenda')->assertOk()->assertDontSee("agendamento={$this->appointment->id}", false);
        $this->get("{$this->url}/novo?agendamento={$this->appointment->id}")->assertForbidden();
        $this->post($this->url, $this->payload())->assertForbidden();

        $this->assertTrue($this->appointment->refresh()->isOpen());
        $this->assertSame(0, Treatment::count());
    }

    public function test_an_appointment_receives_a_single_treatment(): void
    {
        $this->actingAs($this->professional);

        $this->post($this->url, $this->payload())->assertSessionHasNoErrors();
        $this->post($this->url, $this->payload())->assertSessionHasErrors(['appointment_id' => TreatmentRecorder::APPOINTMENT_NOT_OPEN]);

        $this->assertSame(1, Treatment::count());
    }

    public function test_appointment_must_belong_to_the_patient_be_open_and_not_be_in_the_future(): void
    {
        $this->actingAs($this->professional);

        $ofAnotherPatient = Appointment::factory()->at('2026-10-08 11:00')->create();
        $tomorrow = Appointment::factory()->at('2026-10-09 10:00')->create(['patient_id' => $this->patient->id]);
        $closed = array_map(
            fn (AppointmentStatus $status) => Appointment::factory()->at('2026-10-08 10:00')->status($status)->create(['patient_id' => $this->patient->id]),
            [AppointmentStatus::Cancelled, AppointmentStatus::NoShow, AppointmentStatus::Completed],
        );

        foreach ([$ofAnotherPatient, $tomorrow, ...$closed] as $appointment) {
            $this->post($this->url, $this->payload(['appointment_id' => $appointment->id]))->assertSessionHasErrors('appointment_id');
        }

        foreach ([999999, 'abc', ['1']] as $invalid) {
            $this->post($this->url, $this->payload(['appointment_id' => $invalid]))->assertSessionHasErrors('appointment_id');
        }

        $this->assertSame(0, Treatment::count());
        $this->assertTrue($ofAnotherPatient->refresh()->isOpen());
        $this->assertTrue($tomorrow->refresh()->isOpen());

        // Sem botão na agenda para o agendamento de amanhã.
        $this->get('/agenda?data=2026-10-09')->assertOk()->assertDontSee("agendamento={$tomorrow->id}", false);
    }

    public function test_the_recorder_refuses_an_appointment_that_closed_in_the_meantime(): void
    {
        // Passou pela validação e foi cancelado antes de o registro gravar: nada fica pela metade.
        $this->appointment->forceFill(['status' => AppointmentStatus::Cancelled])->save();
        $treatment = Treatment::factory()->make(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id, 'created_by' => $this->professional->id]);

        try {
            app(TreatmentRecorder::class)->record($treatment, [], null, $this->appointment->id, $this->professional);
            $this->fail('O registro deveria ter sido recusado.');
        } catch (ValidationException $exception) {
            $this->assertSame(TreatmentRecorder::APPOINTMENT_NOT_OPEN, $exception->validator->errors()->first('appointment_id'));
        }

        $this->assertSame(0, Treatment::count());
        $this->assertSame(AppointmentStatus::Cancelled, $this->appointment->refresh()->status);
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

    public function test_treatments_still_work_without_an_appointment(): void
    {
        $this->actingAs($this->professional)->post($this->url, $this->payload(['appointment_id' => null]))->assertSessionHasNoErrors();

        $this->assertSame(1, Treatment::count());
        $this->assertTrue($this->appointment->refresh()->isOpen());
        $this->assertNull($this->appointment->treatment_id);
    }

    public function test_a_past_appointment_left_open_can_still_receive_its_treatment(): void
    {
        $yesterday = Appointment::factory()->at('2026-10-07 16:00')->create(['patient_id' => $this->patient->id]);

        $this->actingAs($this->professional)
            ->post($this->url, $this->payload(['appointment_id' => $yesterday->id, 'performed_at' => '2026-10-07T16:10']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Treatment::sole()->id, $yesterday->refresh()->treatment_id);
        $this->assertSame(AppointmentStatus::Completed, $yesterday->status);
    }
}
