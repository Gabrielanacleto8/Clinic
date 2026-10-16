<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira, 13h em São Paulo. A semana vai de segunda 05/10 a domingo 11/10.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    private function appointmentFor(string $patientName, string $localDateTime, array $attributes = []): Appointment
    {
        return Appointment::factory()->at($localDateTime)->create([
            'patient_id' => Patient::factory()->create(['name' => $patientName])->id,
            ...$attributes,
        ]);
    }

    /**
     * Painel lateral com os agendamentos do dia escolhido.
     */
    private function dayPanel(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<section id="dia".*?<\/section>/s', $response->getContent(), $matches), 'Painel do dia não encontrado.');

        return $matches[0];
    }

    public function test_access_follows_the_role_permissions(): void
    {
        $this->get('/agenda')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/agenda')->assertOk()->assertSee('Novo agendamento');

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);

        $this->actingAs(User::factory()->create());
        $this->get('/agenda')->assertForbidden();
        $this->get('/agendamentos/novo')->assertForbidden();
    }

    public function test_view_only_permission_hides_every_action(): void
    {
        $appointment = $this->appointmentFor('Paciente', '2026-10-08 15:00');
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::AgendaView]);

        $this->actingAs(User::factory()->create());

        $this->get('/agenda')->assertOk()->assertSee('Paciente')
            ->assertDontSee('Novo agendamento')->assertDontSee('Remarcar')->assertDontSee('Confirmar presença')->assertDontSee('Cancelar');
        $this->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'confirmed'])->assertForbidden();
    }

    public function test_day_panel_uses_the_clinic_calendar_day_not_utc(): void
    {
        // 23h30 em São Paulo já é dia 9 em UTC, mas continua sendo a agenda do dia 8.
        $this->appointmentFor('Fim do Dia Oito', '2026-10-08 23:30');
        $this->appointmentFor('Madrugada do Nove', '2026-10-09 00:30');
        $this->appointmentFor('Fim do Dia Sete', '2026-10-07 23:45');

        $this->actingAs(User::factory()->create());

        $today = $this->get('/agenda')->assertOk();
        $panel = $this->dayPanel($today);

        $this->assertStringContainsString('Quinta-feira, 8 de outubro de 2026, hoje', $panel);
        $this->assertStringContainsString('Fim do Dia Oito', $panel);
        $this->assertStringNotContainsString('Madrugada do Nove', $panel);
        $this->assertStringNotContainsString('Fim do Dia Sete', $panel);

        // No calendário, cada agendamento cai no seu dia.
        $this->assertSame(
            ['2026-10-07' => ['Fim do Dia Sete'], '2026-10-08' => ['Fim do Dia Oito'], '2026-10-09' => ['Madrugada do Nove']],
            $today->viewData('appointmentsByDay')->map(fn ($day) => $day->pluck('patient.name')->all())->all(),
        );

        $next = $this->dayPanel($this->get('/agenda?data=2026-10-09'));
        $this->assertStringContainsString('Sexta-feira, 9 de outubro', $next);
        $this->assertStringContainsString('Madrugada do Nove', $next);
        $this->assertStringNotContainsString('Fim do Dia Oito', $next);
        $this->assertStringNotContainsString(', hoje', $next);
    }

    public function test_calendar_shows_whole_weeks_and_summarizes_busy_days(): void
    {
        $this->appointmentFor('Primeiro de Outubro', '2026-10-01 09:00');
        $this->appointmentFor('Fim de Setembro', '2026-09-28 09:00');     // segunda da primeira semana exibida
        $this->appointmentFor('Início de Novembro', '2026-11-01 09:00');  // domingo da última semana exibida
        $this->appointmentFor('Fora da Grade', '2026-11-02 09:00');

        foreach (['08:00', '09:00', '10:00', '11:00', '12:00'] as $index => $time) {
            $this->appointmentFor("Lotado {$index}", "2026-10-20 {$time}");
        }

        $this->actingAs(User::factory()->create());

        $this->get('/agenda')
            ->assertOk()
            ->assertSee('Outubro de 2026')
            ->assertSee('Primeiro de Outubro')->assertSee('Fim de Setembro')->assertSee('Início de Novembro')->assertDontSee('Fora da Grade')
            // Dias cheios mostram os três primeiros e quantos faltam.
            ->assertSee('Lotado 0')->assertSee('Lotado 2')->assertDontSee('Lotado 3')->assertSee('+2 no dia')
            ->assertSee('Nenhum agendamento para este dia.')
            // A navegação anda de mês em mês.
            ->assertSee('href="'.route('agenda.index', ['data' => '2026-09-01']).'"', false)
            ->assertSee('href="'.route('agenda.index', ['data' => '2026-11-01']).'"', false);

        // Escolhido o dia, o painel lista todos os agendamentos dele.
        $panel = $this->dayPanel($this->get('/agenda?data=2026-10-20'));

        $this->assertStringContainsString('5 agendamentos', $panel);
        $this->assertStringContainsString('Lotado 3', $panel);
        $this->assertStringContainsString('Lotado 4', $panel);
    }

    public function test_choosing_a_day_opens_its_month_and_marks_the_day(): void
    {
        $this->appointmentFor('Paciente de Novembro', '2026-11-15 10:00');

        $response = $this->actingAs(User::factory()->create())->get('/agenda?data=2026-11-15')->assertOk();

        $response->assertSee('Novembro de 2026')
            ->assertSee('href="'.route('appointments.create', ['data' => '2026-11-15']).'"', false);
        $this->assertStringContainsString('Paciente de Novembro', $this->dayPanel($response));

        // Só o dia escolhido fica marcado, e cada dia do calendário leva ao seu painel.
        $this->assertSame(1, preg_match_all('/<a href="[^"]*data=([\d-]+)#dia"[^>]*aria-current="true"/', $response->getContent(), $marked));
        $this->assertSame(['2026-11-15'], $marked[1]);
        $response->assertSee('data=2026-11-30#dia', false);
    }

    public function test_agenda_runs_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 20) as $day) {
            $this->appointmentFor("Paciente {$day}", '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00');
        }

        foreach (['11:00', '12:00', '14:00'] as $time) {
            $attended = $this->appointmentFor("Hoje {$time}", "2026-10-08 {$time}", ['status' => AppointmentStatus::Completed]);
            $attended->treatment_id = Treatment::factory()->create(['patient_id' => $attended->patient_id])->id;
            $attended->save();
        }

        $this->actingAs(User::factory()->professional()->create());
        DB::enableQueryLog();

        $this->get('/agenda')->assertOk()->assertSee('Ver atendimento')->assertSee('Registrar atendimento');

        // Uma consulta para o mês, uma por relação carregada e uma para os
        // atendimentos do dia, mais sessão e permissões.
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
    }

    public function test_invalid_dates_are_rejected_and_the_old_view_parameter_is_ignored(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/agenda?data=08/10/2026')->assertSessionHasErrors('data');
        $this->get('/agenda?data=2026-13-45')->assertSessionHasErrors('data');
        $this->get('/agenda?data[]=2026-10-08')->assertSessionHasErrors('data');

        // Links antigos, do tempo das visões de dia e semana, continuam abrindo a agenda.
        $this->get('/agenda?visao=semana&data=2026-10-12')->assertOk()->assertSee('Outubro de 2026')->assertSee('Segunda-feira, 12 de outubro');
    }

    public function test_appointments_of_archived_patients_are_hidden(): void
    {
        $this->appointmentFor('Paciente Arquivado', '2026-10-08 15:00')->patient->delete();

        $this->actingAs(User::factory()->admin()->create())->get('/agenda')->assertOk()->assertDontSee('Paciente Arquivado');
    }

    public function test_appointments_of_a_future_day_cannot_be_started_yet(): void
    {
        $this->appointmentFor('Paciente de Amanhã', '2026-10-09 10:00');

        $this->actingAs(User::factory()->professional()->create())->get('/agenda?data=2026-10-09')
            ->assertSee('Paciente de Amanhã')->assertSee('Remarcar')->assertDontSee('Iniciar atendimento');
    }

    public function test_presence_can_be_confirmed_and_the_confirmation_undone(): void
    {
        $appointment = $this->appointmentFor('Paciente', '2026-10-08 15:00');
        $url = "/agendamentos/{$appointment->id}/situacao";

        $this->actingAs(User::factory()->create());
        $this->get('/agenda')->assertSee('Confirmar presença');

        $this->patch($url, ['status' => 'confirmed'])->assertRedirect(route('agenda.index', ['data' => '2026-10-08']))->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
        $this->assertTrue($appointment->isOpen());

        // Confirmado: o botão de confirmar some, as demais ações continuam.
        $this->get('/agenda')->assertSee('Confirmado')->assertDontSee('Confirmar presença')->assertSee('Remarcar')->assertSee('Faltou');

        $this->patch($url, ['status' => 'confirmed'])->assertSessionHasErrors('status');
        $this->patch($url, ['status' => 'scheduled'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);

        $this->patch($url, ['status' => 'confirmed']);
        $this->patch($url, ['status' => 'no_show'])->assertSessionHasNoErrors();
        // Quem faltou só pode ser reaberto, não confirmado direto.
        $this->patch($url, ['status' => 'confirmed'])->assertSessionHasErrors('status');

        $this->assertSame(
            [['scheduled', 'confirmed'], ['confirmed', 'scheduled'], ['scheduled', 'confirmed'], ['confirmed', 'no_show']],
            AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->orderBy('id')->get()
                ->map(fn ($log) => [$log->metadata['from'], $log->metadata['to']])->all(),
        );
    }

    public function test_confirmed_appointments_keep_the_slot_and_can_receive_the_treatment(): void
    {
        $professional = User::factory()->professional()->create();
        $appointment = $this->appointmentFor('Confirmado', '2026-10-08 15:00', ['professional_id' => $professional->id, 'status' => AppointmentStatus::Confirmed]);
        $other = Patient::factory()->create();

        $this->actingAs($professional);

        // O horário confirmado continua ocupado.
        $this->post('/agendamentos', [
            'patient_id' => $other->id, 'professional_id' => $professional->id, 'scheduled_at' => '2026-10-08T15:10', 'duration_minutes' => 30,
        ])->assertSessionHasErrors(['scheduled_at' => AppointmentScheduler::CONFLICT_MESSAGE]);

        // E pode ser remarcado e receber o atendimento como qualquer agendamento em aberto.
        $this->get("/agendamentos/{$appointment->id}/editar")->assertOk();
        $this->get('/agenda')->assertSee('Registrar atendimento');
        $this->get("/pacientes/{$appointment->patient_id}/atendimentos/novo?agendamento={$appointment->id}")->assertOk()->assertSee('Atendimento do agendamento');
    }
}
