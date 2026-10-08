<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_day_view_uses_the_clinic_calendar_day_not_utc(): void
    {
        // 23h30 em São Paulo já é dia 9 em UTC, mas continua sendo a agenda do dia 8.
        $this->appointmentFor('Fim do Dia Oito', '2026-10-08 23:30');
        $this->appointmentFor('Madrugada do Nove', '2026-10-09 00:30');
        $this->appointmentFor('Fim do Dia Sete', '2026-10-07 23:45');

        $this->actingAs(User::factory()->create());

        $this->get('/agenda')->assertSee('Quinta-feira, 8 de outubro de 2026, hoje')
            ->assertSee('Fim do Dia Oito')->assertDontSee('Madrugada do Nove')->assertDontSee('Fim do Dia Sete')
            ->assertSee('data=2026-10-07', false)->assertSee('data=2026-10-09', false);
        $this->get('/agenda?data=2026-10-09')->assertSee('Madrugada do Nove')->assertDontSee('Fim do Dia Oito')->assertSee('Sexta-feira, 9 de outubro');
        $this->get('/agenda?data=2026-10-07')->assertSee('Fim do Dia Sete')->assertDontSee('Fim do Dia Oito');
    }

    public function test_week_view_runs_from_monday_to_sunday(): void
    {
        $this->appointmentFor('Segunda Cedo', '2026-10-05 00:10');
        $this->appointmentFor('Quinta', '2026-10-08 15:00', ['status' => AppointmentStatus::Confirmed]);
        $this->appointmentFor('Domingo Tarde', '2026-10-11 23:50');
        $this->appointmentFor('Domingo Anterior', '2026-10-04 23:50');
        $this->appointmentFor('Segunda Seguinte', '2026-10-12 00:10');

        $this->actingAs(User::factory()->create());

        $this->get('/agenda?visao=semana')
            ->assertOk()
            ->assertSee('5 de outubro a 11 de outubro de 2026')
            ->assertSeeInOrder(['Segunda Cedo', 'Quinta', 'Domingo Tarde'])
            ->assertDontSee('Domingo Anterior')->assertDontSee('Segunda Seguinte')
            // Cada dia leva à visão diária; a navegação anda de sete em sete dias.
            ->assertSee('visao=dia&amp;data=2026-10-11', false)
            ->assertSee('visao=semana&amp;data=2026-10-01', false)
            ->assertSee('visao=semana&amp;data=2026-10-15', false);

        $this->get('/agenda?visao=semana&data=2026-10-12')->assertSee('Segunda Seguinte')->assertDontSee('Quinta');
    }

    public function test_month_view_shows_whole_weeks_and_summarizes_busy_days(): void
    {
        $this->appointmentFor('Primeiro de Outubro', '2026-10-01 09:00');
        $this->appointmentFor('Fim de Setembro', '2026-09-28 09:00');     // segunda da primeira semana exibida
        $this->appointmentFor('Início de Novembro', '2026-11-01 09:00');  // domingo da última semana exibida
        $this->appointmentFor('Fora da Grade', '2026-11-02 09:00');

        foreach (['08:00', '09:00', '10:00', '11:00', '12:00'] as $index => $time) {
            $this->appointmentFor("Lotado {$index}", "2026-10-20 {$time}");
        }

        $this->actingAs(User::factory()->create());

        $this->get('/agenda?visao=mes')
            ->assertOk()
            ->assertSee('Outubro de 2026')
            ->assertSee('Primeiro de Outubro')->assertSee('Fim de Setembro')->assertSee('Início de Novembro')->assertDontSee('Fora da Grade')
            // Dias cheios mostram os três primeiros e quantos faltam.
            ->assertSee('Lotado 0')->assertSee('Lotado 2')->assertDontSee('Lotado 3')->assertSee('+2 no dia')
            ->assertSee('visao=mes&amp;data=2026-09-01', false)
            ->assertSee('visao=mes&amp;data=2026-11-01', false);
    }

    public function test_summary_views_run_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 20) as $day) {
            $this->appointmentFor("Paciente {$day}", '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT).' 10:00');
        }

        $this->actingAs(User::factory()->create());

        foreach (['semana', 'mes'] as $view) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->get("/agenda?visao={$view}")->assertOk();

            // Uma consulta para o intervalo e uma por relação carregada, mais sessão e permissões.
            $this->assertLessThanOrEqual(8, count(DB::getQueryLog()), "Visão {$view}");
        }
    }

    public function test_invalid_date_and_view_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/agenda?data=08/10/2026')->assertSessionHasErrors('data');
        $this->get('/agenda?data=2026-13-45')->assertSessionHasErrors('data');
        $this->get('/agenda?data[]=2026-10-08')->assertSessionHasErrors('data');
        $this->get('/agenda?visao=ano')->assertSessionHasErrors('visao');
    }

    public function test_appointments_of_archived_patients_are_hidden(): void
    {
        $this->appointmentFor('Paciente Arquivado', '2026-10-08 15:00')->patient->delete();

        $this->actingAs(User::factory()->admin()->create());

        foreach (['dia', 'semana', 'mes'] as $view) {
            $this->get("/agenda?visao={$view}")->assertOk()->assertDontSee('Paciente Arquivado');
        }
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

    public function test_confirmed_appointments_keep_the_slot_and_can_start_a_treatment(): void
    {
        $professional = User::factory()->professional()->create();
        $appointment = $this->appointmentFor('Confirmado', '2026-10-08 15:00', ['professional_id' => $professional->id, 'status' => AppointmentStatus::Confirmed]);
        $other = Patient::factory()->create();

        $this->actingAs($professional);

        // O horário confirmado continua ocupado.
        $this->post('/agendamentos', [
            'patient_id' => $other->id, 'professional_id' => $professional->id, 'scheduled_at' => '2026-10-08T15:10', 'duration_minutes' => 30,
        ])->assertSessionHasErrors(['scheduled_at' => AppointmentScheduler::CONFLICT_MESSAGE]);

        // E pode ser remarcado e iniciado como qualquer agendamento em aberto.
        $this->get("/agendamentos/{$appointment->id}/editar")->assertOk();
        $this->get('/agenda')->assertSee('Iniciar atendimento');
        $this->get("/pacientes/{$appointment->patient_id}/atendimentos/novo?agendamento={$appointment->id}")->assertOk()->assertSee('Atendimento do agendamento');
    }
}
