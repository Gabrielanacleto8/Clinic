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
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agenda: o que mantém os horários corretos (arquivamento, reabertura, falta)
 * e as visões por semana e por profissional.
 */
class AgendaRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira, 13h em São Paulo. A semana vai de segunda 05/10 a domingo 11/10.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    public function test_archiving_a_patient_cancels_the_open_appointments_and_frees_the_time(): void
    {
        $professional = User::factory()->professional()->create();
        $patient = Patient::factory()->create();
        $open = Appointment::factory()->at('2026-10-09 14:00')->create(['patient_id' => $patient->id, 'professional_id' => $professional->id]);
        $confirmed = Appointment::factory()->at('2026-10-10 09:00')->status(AppointmentStatus::Confirmed)->create(['patient_id' => $patient->id, 'professional_id' => $professional->id]);
        $missed = Appointment::factory()->at('2026-10-01 09:00')->status(AppointmentStatus::NoShow)->create(['patient_id' => $patient->id, 'professional_id' => $professional->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->delete("/pacientes/{$patient->id}")
            ->assertSessionHas('success', 'Paciente arquivado. Os 2 agendamentos em aberto dele foram cancelados.');

        $this->assertSame(AppointmentStatus::Cancelled, $open->refresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $confirmed->refresh()->status);
        $this->assertSame(AppointmentStatus::NoShow, $missed->refresh()->status);
        $this->assertSame(2, AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->whereJsonContains('metadata->reason', 'patient_archived')->count());

        // O horário ficou livre para outro paciente.
        $this->post('/agendamentos', [
            'patient_id' => Patient::factory()->create()->id,
            'professional_id' => $professional->id,
            'scheduled_at' => '2026-10-09T14:00',
            'duration_minutes' => 30,
        ])->assertSessionHasNoErrors();
    }

    public function test_past_appointments_cannot_be_reopened_and_future_ones_cannot_be_missed(): void
    {
        $this->actingAs(User::factory()->create());

        $old = Appointment::factory()->at('2026-10-01 10:00')->status(AppointmentStatus::Cancelled)->create();
        $this->patch("/agendamentos/{$old->id}/situacao", ['status' => 'scheduled'])->assertSessionHasErrors('status');
        $this->assertSame(AppointmentStatus::Cancelled, $old->refresh()->status);

        $tomorrow = Appointment::factory()->at('2026-10-09 10:00')->create();
        $this->patch("/agendamentos/{$tomorrow->id}/situacao", ['status' => 'no_show'])->assertSessionHasErrors('status');
        $this->patch("/agendamentos/{$tomorrow->id}/situacao", ['status' => 'cancelled'])->assertSessionHasNoErrors();

        // Reaberto, o de amanhã volta ao horário.
        $this->patch("/agendamentos/{$tomorrow->id}/situacao", ['status' => 'scheduled'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Scheduled, $tomorrow->refresh()->status);
    }

    public function test_reopening_requires_an_active_professional(): void
    {
        $appointment = Appointment::factory()->at('2026-10-09 10:00')->status(AppointmentStatus::Cancelled)->create([
            'professional_id' => User::factory()->professional()->inactive()->create()->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'scheduled'])
            ->assertSessionHasErrors('status');
    }

    public function test_status_of_an_archived_patients_appointment_is_not_reachable(): void
    {
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->at('2026-10-09 10:00')->create(['patient_id' => $patient->id]);
        $patient->delete();

        $this->actingAs(User::factory()->create())
            ->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'cancelled'])
            ->assertNotFound();
    }

    public function test_notes_of_an_open_appointment_from_a_past_day_can_be_edited(): void
    {
        $professional = User::factory()->professional()->create();
        $appointment = Appointment::factory()->at('2026-10-06 10:00')->create(['professional_id' => $professional->id]);

        $this->actingAs(User::factory()->create())->put("/agendamentos/{$appointment->id}", [
            'professional_id' => $professional->id,
            'scheduled_at' => '2026-10-06T10:00',
            'duration_minutes' => 30,
            'notes' => 'Ligar para remarcar.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Ligar para remarcar.', $appointment->refresh()->notes);

        // Mudar para outro dia que já passou continua proibido.
        $this->put("/agendamentos/{$appointment->id}", [
            'professional_id' => $professional->id,
            'scheduled_at' => '2026-10-05T10:00',
            'duration_minutes' => 30,
        ])->assertSessionHasErrors('scheduled_at');
    }

    public function test_week_view_and_professional_filter(): void
    {
        $helena = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $bruno = User::factory()->professional()->create(['name' => 'Dr. Bruno']);
        Appointment::factory()->at('2026-10-05 09:00')->create(['patient_id' => Patient::factory()->create(['name' => 'Ana Segunda'])->id, 'professional_id' => $helena->id]);
        Appointment::factory()->at('2026-10-11 09:00')->create(['patient_id' => Patient::factory()->create(['name' => 'Bia Domingo'])->id, 'professional_id' => $bruno->id]);
        Appointment::factory()->at('2026-10-12 09:00')->create(['patient_id' => Patient::factory()->create(['name' => 'Caio Semana Seguinte'])->id, 'professional_id' => $helena->id]);

        $this->actingAs(User::factory()->create());

        $this->get('/agenda?visao=semana&data=2026-10-08')->assertOk()
            ->assertSee('Semana de 5 de outubro a 11 de outubro de 2026')
            ->assertSee('Ana Segunda')->assertSee('Bia Domingo')->assertDontSee('Caio Semana Seguinte')
            ->assertSee(e(route('agenda.index', ['visao' => 'semana', 'data' => '2026-10-15'])), false);

        $this->get("/agenda?visao=semana&data=2026-10-08&profissional={$helena->id}")->assertOk()
            ->assertSee('Ana Segunda')->assertDontSee('Bia Domingo');

        $this->get("/agenda?data=2026-10-08&profissional={$bruno->id}")->assertOk()
            ->assertSee('Bia Domingo')->assertDontSee('Ana Segunda');

        $this->get('/agenda?visao=ano')->assertSessionHasErrors('visao');
    }

    public function test_reading_the_agenda_is_audited_without_personal_data(): void
    {
        $this->actingAs(User::factory()->create())->get('/agenda?visao=semana&data=2026-10-08')->assertOk();

        $log = AuditLog::where('action', AuditAction::AgendaViewed->value)->sole();
        $this->assertEquals(['view' => 'semana', 'from' => '2026-10-05', 'to' => '2026-10-11', 'professional_id' => null], $log->metadata);
    }

    public function test_managing_the_agenda_grants_the_patient_search_it_needs(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::AgendaManage]);

        $this->assertEqualsCanonicalizing(
            [Permission::AgendaManage, Permission::AgendaView, Permission::PatientsView],
            app(RolePermissions::class)->for(UserRole::Receptionist),
        );
    }

    public function test_week_view_with_professional_filter_runs_a_fixed_number_of_queries(): void
    {
        $helena = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $bruno = User::factory()->professional()->create(['name' => 'Dr. Bruno']);

        $schedule = function (int $count, string $time) use ($helena, $bruno): void {
            foreach (range(1, $count) as $index) {
                $day = '2026-10-0'.(5 + $index % 5);
                $professional = $index % 2 === 0 ? $bruno : $helena;
                $minute = str_pad((string) ($index * 2 % 60), 2, '0', STR_PAD_LEFT);
                Appointment::factory()->at("{$day} {$time}:{$minute}", 1)->create(['professional_id' => $professional->id]);
            }

            $attended = Appointment::factory()->at("2026-10-08 {$time}:59", 1)->status(AppointmentStatus::Completed)->create(['professional_id' => $helena->id]);
            $attended->treatment_id = Treatment::factory()->create(['patient_id' => $attended->patient_id])->id;
            $attended->save();
        };

        $count = function () use ($helena): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/agenda?visao=semana&data=2026-10-08&profissional={$helena->id}")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->actingAs(User::factory()->professional()->create());

        $schedule(2, '08');
        $count();
        $few = $count();

        $schedule(20, '15');
        $schedule(20, '17');

        $this->get("/agenda?visao=semana&data=2026-10-08&profissional={$helena->id}")->assertOk()->assertSee('Ver atendimento');

        // Mais agendamentos na semana não aumentam o número de consultas.
        $this->assertSame($few, $count());
        $this->assertLessThanOrEqual(10, $few);
    }

    public function test_professional_filter_is_validated_and_the_agenda_is_protected(): void
    {
        $this->get('/agenda?visao=semana')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/agenda?profissional=abc')->assertSessionHasErrors('profissional');

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->actingAs(User::factory()->create())->get('/agenda?visao=semana')->assertForbidden();

        $this->assertSame(0, AuditLog::where('action', AuditAction::AgendaViewed->value)->count());
    }
}
