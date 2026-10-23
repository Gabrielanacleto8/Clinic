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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira, 13h em São Paulo (16h UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        // Data fixa: a busca devolve a data de nascimento e se o paciente é menor.
        $this->patient = Patient::factory()->create(['name' => 'Marina Albuquerque', 'cpf' => '52998224725', 'birth_date' => '1990-05-20']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'patient_id' => $this->patient->id,
            'professional_id' => $this->professional->id,
            'scheduled_at' => '2026-10-09T14:30',
            'duration_minutes' => 45,
            'procedure_id' => $this->procedure('Toxina botulínica')->id,
            'notes' => 'Prefere ser avisada por mensagem.',
            ...$overrides,
        ];
    }

    private function booked(string $localDateTime, int $minutes = 30, array $attributes = []): Appointment
    {
        return Appointment::factory()->at($localDateTime, $minutes)->create([
            'patient_id' => $this->patient->id,
            'professional_id' => $this->professional->id,
            ...$attributes,
        ]);
    }

    public function test_guests_cannot_reach_the_agenda_routes(): void
    {
        $appointment = $this->booked('2026-10-09 10:00');

        $this->get('/agendamentos/novo')->assertRedirect('/login');
        $this->post('/agendamentos', $this->payload())->assertRedirect('/login');
        $this->put("/agendamentos/{$appointment->id}", $this->payload())->assertRedirect('/login');
        $this->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'cancelled'])->assertRedirect('/login');
        $this->getJson('/pacientes/buscar?q=Marina')->assertUnauthorized();

        $this->assertSame(1, Appointment::count());
        $this->assertTrue($appointment->refresh()->isOpen());
    }

    public function test_reception_can_schedule_a_patient(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->get('/agendamentos/novo')->assertOk()->assertSee('Dra. Helena');

        $this->post('/agendamentos', $this->payload())
            ->assertRedirect(route('agenda.index', ['data' => '2026-10-09']).'#dia')
            ->assertSessionHasNoErrors();

        $appointment = Appointment::firstOrFail();

        $this->assertSame($this->patient->id, $appointment->patient_id);
        $this->assertSame($this->professional->id, $appointment->professional_id);
        // Digitado no horário de São Paulo (UTC-3), gravado em UTC.
        $this->assertSame('2026-10-09 17:30:00', $appointment->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-09 18:15:00', $appointment->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame(45, $appointment->duration_minutes);
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame($user->id, $appointment->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::AppointmentCreated->value,
            'user_id' => $user->id,
            'auditable_id' => $appointment->id,
        ]);

        $this->get('/agenda?data=2026-10-09')->assertSee('Marina Albuquerque')->assertSee('14:30')->assertSee('até 15:15');
    }

    public function test_form_can_open_with_a_patient_and_day_already_chosen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get("/agendamentos/novo?paciente={$this->patient->id}&data=2026-10-20")
            ->assertOk()
            ->assertSee('value="Marina Albuquerque"', false)
            ->assertSee('value="2026-10-20T09:00"', false);

        $this->get("/pacientes/{$this->patient->id}")->assertSee(route('appointments.create', ['paciente' => $this->patient->id]), false);
    }

    public function test_appointment_data_is_validated(): void
    {
        $archived = Patient::factory()->create();
        $archived->delete();

        $this->actingAs(User::factory()->create());

        $invalid = [
            'patient_id' => [null, 999999, $archived->id, 'abc'],
            'professional_id' => [null, User::factory()->create()->id, User::factory()->professional()->inactive()->create()->id],
            'scheduled_at' => [null, '09/10/2026 14:30', '2026-10-07T14:30', '2029-01-01T10:00'],
            'duration_minutes' => [null, 25, 0, 600, 'x'],
            'procedure_id' => [999999, 'abc', $this->procedure('Descontinuado', ['is_active' => false])->id],
            'notes' => [str_repeat('a', 501)],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $this->post('/agendamentos', $this->payload([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(0, Appointment::count());
    }

    public function test_a_walk_in_earlier_today_is_accepted(): void
    {
        // Agora são 13h: registrar um encaixe das 9h de hoje é permitido.
        $this->actingAs(User::factory()->create())
            ->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-08T09:00']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Appointment::count());
    }

    public function test_a_professional_cannot_be_double_booked(): void
    {
        $this->booked('2026-10-09 14:00', 60);
        $this->actingAs(User::factory()->create());

        foreach (['2026-10-09T14:00', '2026-10-09T14:30', '2026-10-09T13:45', '2026-10-09T14:59'] as $overlapping) {
            $this->post('/agendamentos', $this->payload(['scheduled_at' => $overlapping, 'duration_minutes' => 30]))
                ->assertSessionHasErrors('scheduled_at');
        }

        // Um horário que engloba o existente por inteiro também conflita.
        $this->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-09T13:00', 'duration_minutes' => 180]))
            ->assertSessionHasErrors('scheduled_at');

        $this->assertSame(1, Appointment::count());
    }

    public function test_adjacent_slots_other_professionals_and_closed_appointments_do_not_conflict(): void
    {
        $this->booked('2026-10-09 14:00', 60);
        $this->booked('2026-10-09 16:00', 60, ['status' => AppointmentStatus::Cancelled]);
        $this->booked('2026-10-09 17:00', 60, ['status' => AppointmentStatus::NoShow]);
        $other = User::factory()->professional()->create();

        $this->actingAs(User::factory()->create());

        // Termina exatamente quando o outro começa, e começa quando o outro termina.
        $this->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-09T13:30', 'duration_minutes' => 30]))->assertSessionHasNoErrors();
        $this->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-09T15:00', 'duration_minutes' => 30]))->assertSessionHasNoErrors();
        // Mesmo horário, outro profissional.
        $this->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-09T14:00', 'professional_id' => $other->id]))->assertSessionHasNoErrors();
        // Horários de agendamentos cancelados ou com falta ficam livres.
        $this->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-09T16:00', 'duration_minutes' => 30]))->assertSessionHasNoErrors();
        $this->post('/agendamentos', $this->payload(['scheduled_at' => '2026-10-09T17:00', 'duration_minutes' => 30]))->assertSessionHasNoErrors();

        $this->assertSame(8, Appointment::count());
    }

    public function test_appointment_can_be_rescheduled(): void
    {
        // Procedimento fixo: a factory sorteia um, e o teste depende de ele mudar.
        $appointment = $this->booked('2026-10-09 10:00', 30, ['procedure_id' => $this->procedure('Avaliação')->id]);
        $other = User::factory()->professional()->create();
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->get("/agendamentos/{$appointment->id}/editar")->assertOk()->assertSee('Marina Albuquerque');

        // Salvar no mesmo horário não conflita consigo mesmo e não gera auditoria.
        $same = ['scheduled_at' => '2026-10-09T10:00', 'duration_minutes' => 30, 'procedure_id' => $appointment->procedure_id, 'notes' => null];
        $this->put("/agendamentos/{$appointment->id}", $this->payload([...$same, 'patient_id' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::AppointmentUpdated->value]);

        $this->put("/agendamentos/{$appointment->id}", [
            'professional_id' => $other->id,
            'scheduled_at' => '2026-10-12T16:00',
            'duration_minutes' => 60,
            'procedure_id' => $this->procedure('Preenchimento labial')->id,
        ])->assertRedirect(route('agenda.index', ['data' => '2026-10-12']).'#dia')->assertSessionHasNoErrors();

        $appointment->refresh();

        $this->assertSame($other->id, $appointment->professional_id);
        $this->assertSame('2026-10-12 19:00:00', $appointment->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-12 20:00:00', $appointment->ends_at->format('Y-m-d H:i:s'));

        $log = AuditLog::where('action', AuditAction::AppointmentUpdated->value)->sole();

        $this->assertEqualsCanonicalizing(['professional_id', 'scheduled_at', 'duration_minutes', 'procedure_id'], $log->metadata['fields']);
        $this->assertStringNotContainsString('Preenchimento', (string) json_encode($log->metadata));
    }

    public function test_rescheduling_respects_conflicts_and_never_changes_the_patient(): void
    {
        $this->booked('2026-10-09 14:00', 60);
        $appointment = $this->booked('2026-10-09 10:00', 30);
        $otherPatient = Patient::factory()->create();

        $this->actingAs(User::factory()->create());

        $this->put("/agendamentos/{$appointment->id}", $this->payload(['patient_id' => null, 'scheduled_at' => '2026-10-09T14:30']))
            ->assertSessionHasErrors('scheduled_at');

        $this->put("/agendamentos/{$appointment->id}", $this->payload(['patient_id' => $otherPatient->id, 'scheduled_at' => '2026-10-09T11:00']))
            ->assertSessionHasErrors('patient_id');

        $appointment->refresh();

        $this->assertSame($this->patient->id, $appointment->patient_id);
        $this->assertSame('2026-10-09 13:00:00', $appointment->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_closed_appointments_cannot_be_rescheduled(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ([AppointmentStatus::Completed, AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $index => $status) {
            $appointment = $this->booked('2026-10-09 1'.$index.':00', 30, ['status' => $status]);

            $this->get("/agendamentos/{$appointment->id}/editar")->assertForbidden();
            $this->put("/agendamentos/{$appointment->id}", $this->payload(['patient_id' => null]))->assertForbidden();
        }
    }

    public function test_appointment_can_be_cancelled_marked_as_no_show_and_reopened(): void
    {
        $appointment = $this->booked('2026-10-08 15:00');
        $url = "/agendamentos/{$appointment->id}/situacao";
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->patch($url, ['status' => 'no_show'])->assertRedirect(route('agenda.index', ['data' => '2026-10-08']).'#dia')->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::NoShow, $appointment->refresh()->status);

        $this->patch($url, ['status' => 'scheduled'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);

        $this->patch($url, ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);

        $logs = AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->orderBy('id')->get();

        $this->assertSame(
            [['scheduled', 'no_show'], ['no_show', 'scheduled'], ['scheduled', 'cancelled']],
            $logs->map(fn ($log) => [$log->metadata['from'], $log->metadata['to']])->all(),
        );
        $this->assertSame($user->id, $logs[0]->user_id);
    }

    public function test_invalid_status_changes_are_rejected(): void
    {
        $open = $this->booked('2026-10-08 15:00');
        $done = $this->booked('2026-10-08 09:00', 30, ['status' => AppointmentStatus::Completed]);
        $cancelled = $this->booked('2026-10-08 10:00', 30, ['status' => AppointmentStatus::Cancelled]);

        $this->actingAs(User::factory()->admin()->create());

        // "Atendido" só nasce do registro do atendimento, nunca de um clique.
        $this->patch("/agendamentos/{$open->id}/situacao", ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->patch("/agendamentos/{$open->id}/situacao", ['status' => 'scheduled'])->assertSessionHasErrors('status');
        $this->patch("/agendamentos/{$open->id}/situacao", ['status' => 'qualquer'])->assertSessionHasErrors('status');
        $this->patch("/agendamentos/{$open->id}/situacao", [])->assertSessionHasErrors('status');

        // Um agendamento atendido não muda mais.
        foreach (['scheduled', 'cancelled', 'no_show'] as $status) {
            $this->patch("/agendamentos/{$done->id}/situacao", ['status' => $status])->assertSessionHasErrors('status');
        }

        // Cancelado só pode ser reaberto.
        $this->patch("/agendamentos/{$cancelled->id}/situacao", ['status' => 'no_show'])->assertSessionHasErrors('status');

        $this->assertSame(AppointmentStatus::Scheduled, $open->refresh()->status);
        $this->assertSame(AppointmentStatus::Completed, $done->refresh()->status);
        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->refresh()->status);
    }

    public function test_reopening_onto_an_occupied_slot_is_rejected(): void
    {
        $cancelled = $this->booked('2026-10-09 14:00', 60, ['status' => AppointmentStatus::Cancelled]);
        $this->booked('2026-10-09 14:30', 30);

        $this->actingAs(User::factory()->create())
            ->patch("/agendamentos/{$cancelled->id}/situacao", ['status' => 'scheduled'])
            ->assertSessionHasErrors('scheduled_at');

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->refresh()->status);
    }

    public function test_notes_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $this->actingAs(User::factory()->create())->post('/agendamentos', $this->payload());

        $this->assertStringNotContainsString('mensagem', DB::table('appointments')->value('notes'));
        $this->assertSame('Prefere ser avisada por mensagem.', Appointment::first()->notes);
        $this->assertArrayNotHasKey('notes', Appointment::first()->toArray());
    }

    public function test_protected_columns_cannot_be_mass_assigned(): void
    {
        $other = User::factory()->create();

        $this->actingAs(User::factory()->create())->post('/agendamentos', $this->payload([
            'status' => 'completed',
            'treatment_id' => Treatment::factory()->create()->id,
            'created_by' => $other->id,
            'ends_at' => '2030-01-01 00:00:00',
        ]))->assertSessionHasNoErrors();

        $appointment = Appointment::firstOrFail();

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertNull($appointment->treatment_id);
        $this->assertNotSame($other->id, $appointment->created_by);
        $this->assertSame('2026-10-09 18:15:00', $appointment->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_appointments_of_an_archived_patient_are_unreachable(): void
    {
        $appointment = $this->booked('2026-10-09 10:00');
        $this->patient->delete();

        $this->actingAs(User::factory()->admin()->create());

        $this->get("/agendamentos/{$appointment->id}/editar")->assertNotFound();
        $this->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'cancelled'])->assertNotFound();
    }

    public function test_patient_search_returns_only_what_identifies_the_patient(): void
    {
        Patient::factory()->create(['name' => 'Bruno Rocha', 'cpf' => '11144477735']);
        $archived = Patient::factory()->create(['name' => 'Marina Arquivada']);
        $archived->delete();

        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/pacientes/buscar?q=marina')->assertOk();

        $response->assertExactJson([[
            'id' => $this->patient->id,
            'name' => 'Marina Albuquerque',
            'cpf' => '***.982.247-**',
            'birth_date' => $this->patient->birth_date->format('d/m/Y'),
            'phone' => $this->patient->formattedPhone(),
            'minor' => false,
        ]]);
        $this->assertStringNotContainsString('52998224725', $response->getContent());

        // A busca por CPF completo também funciona.
        $this->getJson('/pacientes/buscar?q=111.444.777-35')->assertOk()->assertJsonPath('0.name', 'Bruno Rocha');
        $this->getJson('/pacientes/buscar?q=zzz')->assertOk()->assertExactJson([]);
    }

    public function test_patient_search_is_bounded(): void
    {
        Patient::factory()->count(12)->sequence(fn ($sequence) => ['name' => 'Paciente Teste '.$sequence->index])->create();

        $this->actingAs(User::factory()->create());

        $this->getJson('/pacientes/buscar?q=Paciente')->assertOk()->assertJsonCount(8);
        $this->getJson('/pacientes/buscar?q=a')->assertUnprocessable();
        $this->getJson('/pacientes/buscar')->assertUnprocessable();
        $this->getJson('/pacientes/buscar?q='.str_repeat('a', 101))->assertUnprocessable();
        // Curingas do LIKE são tratados como texto.
        $this->getJson('/pacientes/buscar?q=%25%25')->assertOk()->assertExactJson([]);
    }
}
