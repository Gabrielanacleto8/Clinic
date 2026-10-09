<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TreatmentQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira, 13h em São Paulo (16h UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    /**
     * Agendamento com presença confirmada, salvo quando o teste pede outra situação.
     */
    private function appointmentFor(string $patientName, string $localDateTime, AppointmentStatus $status = AppointmentStatus::Confirmed, array $attributes = []): Appointment
    {
        return Appointment::factory()->at($localDateTime)->status($status)->create([
            'patient_id' => Patient::factory()->create(['name' => $patientName])->id,
            ...$attributes,
        ]);
    }

    private function draftFor(string $patientName, string $localDateTime, array $attributes = []): Treatment
    {
        return Treatment::factory()->create([
            'patient_id' => Patient::factory()->create(['name' => $patientName])->id,
            'performed_at' => CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo')->utc(),
            ...$attributes,
        ]);
    }

    public function test_access_follows_the_agenda_permission(): void
    {
        $this->get('/atendimentos')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/atendimentos')->assertOk()->assertSee('Em atendimento')->assertSee('Em espera');

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->appointmentFor('Não Deve Aparecer', '2026-10-08 14:00');

        $this->actingAs(User::factory()->create());
        $this->get('/atendimentos')->assertForbidden()->assertDontSee('Não Deve Aparecer');
        $this->get('/pacientes')->assertOk()->assertDontSee('href="'.route('treatments.index').'"', false);
    }

    public function test_lists_who_is_being_treated_and_who_is_waiting(): void
    {
        $this->draftFor('Em Atendimento Avulso', '2026-10-08 12:40');
        $started = $this->appointmentFor('Em Atendimento Agendado', '2026-10-08 12:00');
        $started->treatment_id = Treatment::factory()->create(['patient_id' => $started->patient_id, 'performed_at' => now()->subMinutes(50)])->id;
        $started->save();

        $this->appointmentFor('Espera das Duas', '2026-10-08 14:00');
        $this->appointmentFor('Espera do Meio-dia', '2026-10-08 12:30');

        // Fora da tela: ainda não chegou, outro dia, encerrado, finalizado e descartado.
        $this->appointmentFor('Sem Presença', '2026-10-08 15:00', AppointmentStatus::Scheduled);
        $this->appointmentFor('Confirmado de Amanhã', '2026-10-09 10:00');
        $this->appointmentFor('Confirmado de Ontem', '2026-10-07 10:00');
        $this->appointmentFor('Cancelado', '2026-10-08 16:00', AppointmentStatus::Cancelled);
        $this->appointmentFor('Faltou', '2026-10-08 09:00', AppointmentStatus::NoShow);
        Treatment::factory()->finalized()->create(['patient_id' => Patient::factory()->create(['name' => 'Já Finalizado'])->id]);
        $this->draftFor('Rascunho Descartado', '2026-10-08 11:00')->delete();

        $response = $this->actingAs(User::factory()->professional()->create())->get('/atendimentos')->assertOk();

        $response
            ->assertSeeInOrder(['Em atendimento', 'Em Atendimento Agendado', 'Em Atendimento Avulso', 'Em espera', 'Espera do Meio-dia', 'Espera das Duas'])
            ->assertSee('Mais 1 paciente agendado para hoje ainda sem presença confirmada.')
            ->assertDontSee('Sem Presença')->assertDontSee('Confirmado de Amanhã')->assertDontSee('Confirmado de Ontem')
            ->assertDontSee('Cancelado')->assertDontSee('Faltou')->assertDontSee('Já Finalizado')->assertDontSee('Rascunho Descartado');

        $this->assertSame(2, $response->viewData('inProgress')->count());
        $this->assertSame(2, $response->viewData('waiting')->count());
    }

    public function test_empty_queue_explains_how_a_patient_enters_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/atendimentos')
            ->assertOk()
            ->assertSee('Nenhum paciente em atendimento.')
            ->assertSee('O paciente entra na espera quando a presença é confirmada na agenda.')
            ->assertDontSee('ainda sem presença confirmada');
    }

    public function test_confirming_presence_moves_the_patient_into_the_queue_and_starting_moves_them_on(): void
    {
        $professional = User::factory()->professional()->create();
        $appointment = $this->appointmentFor('Paciente da Fila', '2026-10-08 12:30', AppointmentStatus::Scheduled, ['professional_id' => $professional->id]);

        $this->actingAs($professional);
        $this->assertTrue($this->get('/atendimentos')->viewData('waiting')->isEmpty());

        $this->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->assertSame([$appointment->id], $this->get('/atendimentos')->viewData('waiting')->modelKeys());

        $this->post("/pacientes/{$appointment->patient_id}/atendimentos", [
            'performed_at' => '2026-10-08T12:45',
            'procedure_id' => $appointment->procedure_id,
            'description' => 'Avaliação inicial.',
            'appointment_id' => $appointment->id,
        ])->assertSessionHasNoErrors();

        $response = $this->get('/atendimentos');
        $this->assertTrue($response->viewData('waiting')->isEmpty());
        $this->assertSame([Treatment::sole()->id], $response->viewData('inProgress')->modelKeys());
    }

    public function test_professional_gets_the_actions_and_reception_only_follows_the_queue(): void
    {
        $professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $waiting = $this->appointmentFor('Paciente Esperando', '2026-10-08 13:30', attributes: ['procedure_id' => $this->procedure('Limpeza de pele')->id]);
        $own = $this->draftFor('Paciente da Helena', '2026-10-08 12:40', ['professional_id' => $professional->id, 'procedure_id' => $this->procedure('Toxina botulínica')->id]);
        $others = $this->draftFor('Paciente de Outro', '2026-10-08 12:50');

        $startUrl = route('patients.treatments.create', [$waiting->patient_id, 'agendamento' => $waiting->id]);
        $ownUrl = route('patients.treatments.show', [$own->patient_id, $own]);
        $othersUrl = route('patients.treatments.show', [$others->patient_id, $others]);

        $this->actingAs($professional)->get('/atendimentos')
            ->assertSee('Toxina botulínica')->assertSee('Dra. Helena')
            ->assertSee('href="'.e($startUrl).'"', false)->assertSee('Iniciar atendimento')
            ->assertSee('href="'.$ownUrl.'"', false)->assertSee('Continuar atendimento')
            // O atendimento de outro profissional pode ser aberto, mas não continuado.
            ->assertSee('href="'.$othersUrl.'"', false)->assertSee('Ver atendimento');

        // Recepção: vê quem está sendo atendido e por quem, sem nada do prontuário.
        $this->actingAs(User::factory()->create())->get('/atendimentos')
            ->assertOk()
            ->assertSee('Paciente da Helena')->assertSee('Dra. Helena')->assertSee('Paciente Esperando')->assertSee('Limpeza de pele')
            ->assertDontSee('Toxina botulínica')
            ->assertDontSee('Iniciar atendimento')->assertDontSee('Continuar atendimento')->assertDontSee('Ver atendimento')
            ->assertDontSee($ownUrl, false);
    }

    public function test_a_treatment_left_open_on_another_day_stays_listed_with_its_date(): void
    {
        $this->draftFor('Esquecido de Segunda', '2026-10-05 17:00');
        $this->draftFor('Atendido Agora', '2026-10-08 12:50');

        $this->actingAs(User::factory()->professional()->create())->get('/atendimentos')
            ->assertSeeInOrder(['Esquecido de Segunda', 'Atendido Agora'])
            ->assertSee('desde 05/10');
    }

    public function test_archived_patients_are_left_out(): void
    {
        $this->appointmentFor('Arquivado na Espera', '2026-10-08 14:00')->patient->delete();
        $this->draftFor('Arquivado em Atendimento', '2026-10-08 12:40')->patient->delete();

        $this->actingAs(User::factory()->admin()->create())->get('/atendimentos')
            ->assertOk()->assertDontSee('Arquivado na Espera')->assertDontSee('Arquivado em Atendimento');
    }

    public function test_sidebar_lists_the_module_right_below_the_agenda(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/atendimentos')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $sidebar));

        $link = '<a href="'.preg_quote(route('treatments.index'), '/').'"';
        $agenda = '<a href="'.preg_quote(route('agenda.index'), '/').'"';

        $this->assertMatchesRegularExpression("/{$link}[^>]*aria-current=\"page\"/", $sidebar[0]);
        $this->assertDoesNotMatchRegularExpression("/{$agenda}[^>]*aria-current=\"page\"/", $sidebar[0]);
        // Entre a Agenda e o item de Atendimentos não há nenhum outro módulo.
        $this->assertMatchesRegularExpression("/{$agenda}(?:(?!<a ).)*{$link}/s", $sidebar[0]);
    }

    public function test_queue_runs_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 8) as $index) {
            $this->appointmentFor("Espera {$index}", '2026-10-08 14:'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $this->draftFor("Atendimento {$index}", '2026-10-08 12:'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        $this->actingAs(User::factory()->professional()->create());
        DB::enableQueryLog();

        $this->get('/atendimentos')->assertOk()->assertSee('Espera 8')->assertSee('Atendimento 8');

        // Duas listas, cada uma com três relações, mais sessão e permissões.
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
    }

    public function test_output_is_escaped(): void
    {
        $this->appointmentFor('<script>alert(1)</script>', '2026-10-08 14:00', attributes: ['procedure_id' => $this->procedure('<b>negrito</b>')->id]);
        $this->draftFor('<img src=x onerror=alert(2)>', '2026-10-08 12:40');

        $this->actingAs(User::factory()->admin()->create())->get('/atendimentos')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>negrito</b>', false)
            ->assertDontSee('<img src=x', false);
    }
}
