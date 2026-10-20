<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Depois de registrar um atendimento, o usuário continua no módulo em que
 * estava: Início, Agenda ou Atendimentos.
 */
class TreatmentOriginTest extends TestCase
{
    use RefreshDatabase;

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
            'performed_at' => '2026-10-08T12:40',
            'procedure_id' => $this->procedure('Toxina botulínica')->id,
            'description' => 'Aplicados 20 U na glabela.',
            'amount' => '0',
            ...$overrides,
        ];
    }

    private function dialogPayload(string $origin): array
    {
        return $this->payload(['_dialog' => 'new-treatment', 'patient_id' => $this->patient->id, 'origem' => $origin]);
    }

    private function appointment(string $at = '2026-10-07 16:00'): Appointment
    {
        return Appointment::factory()->at($at)->create(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id]);
    }

    public function test_the_dialog_returns_to_home_or_to_the_treatment_list(): void
    {
        $this->actingAs($this->professional);

        $this->from('/')->post('/atendimentos', $this->dialogPayload('inicio'))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Atendimento registrado. Estoque e financeiro foram atualizados.');

        $first = Treatment::sole();
        $this->assertSame(
            ['url' => route('patients.treatments.show', [$this->patient, $first]), 'label' => 'Ver o atendimento'],
            session('success_link'),
        );

        // A mensagem leva o atalho para o registro, sem tirar o usuário do Início.
        $this->get('/')->assertOk()->assertSee('href="'.route('patients.treatments.show', [$this->patient, $first]).'"', false)->assertSee('Ver o atendimento');

        $this->from('/atendimentos')->post('/atendimentos', $this->dialogPayload('atendimentos'))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/atendimentos');

        $this->assertSame(2, Treatment::count());
    }

    public function test_the_pages_tell_the_form_where_it_was_opened(): void
    {
        $this->actingAs($this->professional);

        $this->get('/')->assertOk()->assertSee('name="origem" value="inicio"', false);
        $this->get('/atendimentos')->assertOk()->assertSee('name="origem" value="atendimentos"', false);

        // Agenda de hoje no Início e lista do dia na Agenda: o link de registro leva a origem.
        $appointment = $this->appointment('2026-10-08 12:00');
        $this->get('/')->assertOk()->assertSee('agendamento='.$appointment->id.'&amp;origem=inicio', false);
        $this->get('/agenda?data=2026-10-08')->assertOk()->assertSee('agendamento='.$appointment->id.'&amp;origem=agenda', false);
    }

    public function test_from_the_agenda_the_form_stays_in_the_agenda_and_returns_to_the_appointment_day(): void
    {
        $appointment = $this->appointment();
        $create = "/pacientes/{$this->patient->id}/atendimentos/novo?agendamento={$appointment->id}&origem=agenda";

        $page = $this->actingAs($this->professional)->get($create)
            ->assertOk()
            ->assertSee('name="origem" value="agenda"', false)
            ->assertSee('← Agenda')
            ->getContent();

        // O menu marca a Agenda, e não Pacientes.
        $this->assertMatchesRegularExpression('/href="[^"]*\/agenda"[^>]*aria-current="page"/', $page);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/pacientes"[^>]*aria-current="page"/', $page);

        $this->from($create)->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload(['appointment_id' => $appointment->id, 'origem' => 'agenda']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/agenda?data=2026-10-07');

        $this->assertSame(Treatment::sole()->id, $appointment->refresh()->treatment_id);
    }

    public function test_a_validation_error_keeps_the_user_on_the_form_with_the_origin(): void
    {
        $appointment = $this->appointment();
        $create = "/pacientes/{$this->patient->id}/atendimentos/novo?agendamento={$appointment->id}&origem=agenda";

        $this->actingAs($this->professional)->from($create)
            ->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload(['appointment_id' => $appointment->id, 'origem' => 'agenda', 'description' => '']))
            ->assertRedirect($create)
            ->assertSessionHasErrors('description');

        $this->get($create)->assertOk()->assertSee('name="origem" value="agenda"', false);
        $this->assertSame(0, Treatment::count());
    }

    public function test_only_known_origins_are_accepted(): void
    {
        $this->actingAs($this->professional)
            ->post('/atendimentos', $this->dialogPayload('https://exemplo.com'))
            ->assertSessionHasErrors('origem');

        // Origem desconhecida na URL do formulário é ignorada.
        $this->get("/pacientes/{$this->patient->id}/atendimentos/novo?origem=https://exemplo.com")
            ->assertOk()
            ->assertDontSee('name="origem"', false)
            ->assertSee('← Marina Duarte');
        $this->get("/pacientes/{$this->patient->id}/atendimentos/novo?origem[]=agenda")
            ->assertOk()
            ->assertDontSee('name="origem"', false);

        $this->assertSame(0, Treatment::count());
    }

    public function test_without_origin_the_registered_treatment_opens_as_before(): void
    {
        $this->actingAs($this->professional)->post("/pacientes/{$this->patient->id}/atendimentos", $this->payload())
            ->assertRedirect("/pacientes/{$this->patient->id}/atendimentos/".Treatment::sole()->id);
    }
}
