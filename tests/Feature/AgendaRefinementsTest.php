<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refinamentos de agenda e registro: data do agendamento, contexto mantido,
 * conflito explicado, pendências e retornos no Início, anulação com volta.
 */
class AgendaRefinementsTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira, 13h em São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create(['name' => 'Marina Albuquerque', 'phone' => '11987654321']);
    }

    private function appointment(string $at, array $attributes = []): Appointment
    {
        return Appointment::factory()->at($at)->create(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id, ...$attributes]);
    }

    /**
     * @return array<string, mixed>
     */
    private function treatmentPayload(array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->procedure('Toxina botulínica', ['price' => 1200])->id,
            'description' => 'Aplicados 30 U.',
            'amount' => '0',
            ...$overrides,
        ];
    }

    public function test_registering_from_a_past_appointment_opens_with_its_date_and_refuses_another_day(): void
    {
        $appointment = $this->appointment('2026-10-06 15:30');
        $url = "/pacientes/{$this->patient->id}/atendimentos";

        $this->actingAs($this->professional)
            ->get("{$url}/novo?agendamento={$appointment->id}")
            ->assertOk()
            ->assertSee('value="2026-10-06T15:30"', false);

        $this->post($url, $this->treatmentPayload(['appointment_id' => $appointment->id, 'performed_at' => '2026-10-08T12:00']))
            ->assertSessionHasErrors('performed_at');
        $this->assertSame(0, Treatment::count());

        $this->post($url, $this->treatmentPayload(['appointment_id' => $appointment->id, 'performed_at' => '2026-10-06T15:40']))
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-06', Treatment::sole()->performed_at->setTimezone('America/Sao_Paulo')->format('Y-m-d'));
    }

    public function test_the_dialog_closes_the_appointment_of_the_same_professional_and_procedure(): void
    {
        $other = User::factory()->professional()->create();
        $procedure = $this->procedure('Toxina botulínica', ['price' => 1200]);
        $theirs = $this->appointment('2026-10-08 09:00', ['professional_id' => $other->id, 'procedure_id' => $procedure->id]);
        $mine = $this->appointment('2026-10-08 11:00', ['procedure_id' => $procedure->id]);

        $this->actingAs($this->professional)
            ->post('/atendimentos', $this->treatmentPayload(['patient_id' => $this->patient->id, 'procedure_id' => $procedure->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(AppointmentStatus::Completed, $mine->refresh()->status);
        $this->assertSame(AppointmentStatus::Scheduled, $theirs->refresh()->status);
    }

    public function test_rescheduling_a_confirmed_appointment_asks_for_a_new_confirmation(): void
    {
        $appointment = $this->appointment('2026-10-09 10:00', ['status' => AppointmentStatus::Confirmed]);

        $this->actingAs(User::factory()->create())->put("/agendamentos/{$appointment->id}", [
            'professional_id' => $this->professional->id,
            'scheduled_at' => '2026-10-09T14:00',
            'duration_minutes' => 30,
        ])->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $message) => str_contains($message, 'confirme de novo'));

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);

        // Só mudar as observações mantém a confirmação.
        $appointment->forceFill(['status' => AppointmentStatus::Confirmed])->save();
        $this->put("/agendamentos/{$appointment->id}", [
            'professional_id' => $this->professional->id,
            'scheduled_at' => '2026-10-09T14:00',
            'duration_minutes' => 30,
            'notes' => 'Trazer exames.',
        ])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
    }

    public function test_conflict_message_says_the_busy_interval_and_the_next_free_slot(): void
    {
        $this->appointment('2026-10-09 10:00', ['procedure_id' => $this->procedure('Peeling')->id]);
        $this->appointment('2026-10-09 10:30');

        $this->actingAs(User::factory()->create())->post('/agendamentos', [
            'patient_id' => Patient::factory()->create()->id,
            'professional_id' => $this->professional->id,
            'scheduled_at' => '2026-10-09T10:15',
            'duration_minutes' => 30,
        ])->assertSessionHasErrors('scheduled_at');

        $message = session('errors')->first('scheduled_at');
        $this->assertStringContainsString('das 10:00 às 10:30 (Peeling)', $message);
        $this->assertStringContainsString('Próximo horário livre com essa duração: 11:00', $message);
        $this->assertStringNotContainsString('Marina', $message);
    }

    public function test_agenda_actions_keep_the_view_and_filter_and_errors_go_to_the_top(): void
    {
        $appointment = $this->appointment('2026-10-09 10:00');

        $this->actingAs(User::factory()->create())
            ->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'confirmed', 'visao' => 'semana', 'profissional' => $this->professional->id])
            ->assertRedirect(route('agenda.index', ['visao' => 'semana', 'profissional' => $this->professional->id, 'data' => '2026-10-09']).'#dia');

        // Falta em dia futuro: o botão nem aparece e o envio volta com o erro no aviso do topo, com o paciente.
        $this->get('/agenda?data=2026-10-09')->assertOk()->assertDontSee('>Faltou</button>', false);
        $this->from('/agenda?data=2026-10-09')->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'no_show'])
            ->assertRedirect('/agenda?data=2026-10-09')
            ->assertSessionHasErrors('status');

        $this->from('/agenda?data=2026-10-09')->followingRedirects()
            ->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'no_show'])
            ->assertSee('Confira o campo destacado')->assertSee('(09/10 10:00')->assertDontSee('10:00, Marina Albuquerque)');
    }

    public function test_agenda_shows_phone_links_and_a_professional_opens_their_own_agenda_by_default(): void
    {
        $this->appointment('2026-10-08 15:00');
        $other = User::factory()->professional()->create(['name' => 'Dr. Bruno']);
        $this->appointment('2026-10-08 16:00', ['professional_id' => $other->id, 'patient_id' => Patient::factory()->create(['name' => 'Paciente do Bruno'])->id]);

        $this->actingAs($this->professional)->get('/agenda')->assertOk()
            ->assertSee('Marina Albuquerque')->assertDontSee('Paciente do Bruno')
            ->assertSee('tel:+5511987654321')->assertSee('https://wa.me/5511987654321');

        $this->get('/agenda?profissional=todos')->assertSee('Paciente do Bruno');

        // A recepção vê todos por padrão.
        $this->actingAs(User::factory()->create())->get('/agenda')->assertSee('Paciente do Bruno');
    }

    public function test_occupancy_lists_busy_slots_without_patient_names(): void
    {
        $this->appointment('2026-10-09 10:00', ['procedure_id' => $this->procedure('Peeling')->id]);

        $this->actingAs(User::factory()->create())
            ->getJson('/agenda/ocupacao?data=2026-10-09&profissional='.$this->professional->id)
            ->assertOk()
            ->assertExactJson(['slots' => [['from' => '10:00', 'to' => '10:30', 'procedure' => 'Peeling']]]);

        $this->getJson('/agenda/ocupacao?data=x&profissional=1')->assertUnprocessable();

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->actingAs(User::factory()->create())->getJson('/agenda/ocupacao?data=2026-10-09&profissional='.$this->professional->id)->assertForbidden();
    }

    public function test_home_lists_pending_registrations_and_returns_to_schedule(): void
    {
        $pending = $this->appointment('2026-10-06 10:00');
        $this->appointment('2026-10-07 10:00', ['status' => AppointmentStatus::NoShow, 'patient_id' => Patient::factory()->create(['name' => 'Faltou Sem Pendência'])->id]);

        $returned = Patient::factory()->create(['name' => 'Retorno Pendente']);
        Treatment::factory()->create(['patient_id' => $returned->id, 'performed_at' => '2026-09-25 15:00:00', 'return_date' => '2026-10-10', 'procedure_id' => $this->procedure('Toxina botulínica')->id]);
        $scheduled = Patient::factory()->create(['name' => 'Retorno Já Marcado']);
        Treatment::factory()->create(['patient_id' => $scheduled->id, 'performed_at' => '2026-09-25 15:00:00', 'return_date' => '2026-10-10']);
        Appointment::factory()->at('2026-10-10 09:00')->create(['patient_id' => $scheduled->id]);

        $html = $this->actingAs($this->professional)->get('/')->assertOk()
            ->assertSee('Pendentes de registro')->assertSee('Marina Albuquerque')->assertDontSee('Faltou Sem Pendência')
            ->assertSee("agendamento={$pending->id}", false)
            ->assertSee('Retornos previstos sem agendamento')->assertSee('Retorno Pendente')
            ->assertSee(e(route('appointments.create', ['paciente' => $returned->id, 'data' => '2026-10-10'])), false)
            ->getContent();

        // O retorno já marcado aparece nos próximos agendamentos, não na lista de retornos.
        $this->assertSame(1, preg_match('/<section[^>]*aria-labelledby="retornos".*?<\/section>/s', $html, $section));
        $this->assertStringNotContainsString('Retorno Já Marcado', $section[0]);

        // A recepção vê as pendências, mas não os retornos, que são do prontuário.
        $this->actingAs(User::factory()->create())->get('/')->assertSee('Pendentes de registro')->assertDontSee('Retornos previstos');
    }

    public function test_registering_offers_to_schedule_the_return_and_to_add_photos(): void
    {
        $this->actingAs($this->professional)
            ->from('/')
            ->post('/atendimentos', $this->treatmentPayload(['patient_id' => $this->patient->id, '_dialog' => 'new-treatment', 'origem' => 'inicio', 'return_date' => '2026-10-22']))
            ->assertSessionHasNoErrors();

        $labels = array_column(session('success_links'), 'label');
        $this->assertSame(['Ver o atendimento', 'Adicionar fotos', 'Agendar o retorno de 22/10'], $labels);
        $this->assertStringContainsString('data=2026-10-22', session('success_links')[2]['url']);
    }

    public function test_voided_treatment_offers_the_correct_registration_and_takes_no_attachments(): void
    {
        $appointment = $this->appointment('2026-10-08 11:00');
        $admin = User::factory()->admin()->create();
        $url = "/pacientes/{$this->patient->id}/atendimentos";

        $this->actingAs($this->professional)->post($url, $this->treatmentPayload(['appointment_id' => $appointment->id, 'performed_at' => '2026-10-08T11:30']))->assertSessionHasNoErrors();
        $treatment = Treatment::sole();

        $this->actingAs($admin)->post("{$url}/{$treatment->id}/anulacao", ['reason' => 'Registrado no paciente errado.'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'voltou para a agenda'));

        $this->get("{$url}/{$treatment->id}")->assertOk()
            ->assertSee('Registrar o atendimento correto')
            ->assertSee(e(route('patients.treatments.create', [$this->patient, 'agendamento' => $appointment->id])), false)
            ->assertDontSee('Adicionar fotos')->assertDontSee('Registrar evolução');

        $this->post("{$url}/{$treatment->id}/evolucoes", ['body' => 'Não deveria entrar.'])->assertForbidden();
        $this->post("{$url}/{$treatment->id}/documentos", ['title' => 'x'])->assertForbidden();
    }

    public function test_the_form_warns_about_typical_products_without_stock_and_the_received_box_starts_unchecked(): void
    {
        $procedure = $this->procedure('Toxina botulínica', ['price' => 1200]);
        $procedure->products()->sync([Product::factory()->create(['name' => 'Sem Lote'])->id => ['quantity' => 1]]);
        $appointment = $this->appointment('2026-10-08 11:00', ['procedure_id' => $procedure->id]);

        app(RolePermissions::class)->sync(UserRole::Professional, [...Permission::defaultsFor(UserRole::Professional), Permission::FinanceReceive]);

        $this->actingAs($this->professional->fresh())
            ->get("/pacientes/{$this->patient->id}/atendimentos/novo?agendamento={$appointment->id}")
            ->assertOk()
            ->assertSee('Sem lote disponível no estoque, não sugeridos:')->assertSee('Sem Lote')
            ->assertSee('name="first_installment_paid" value="1"', false)
            ->assertDontSee('accent-teal-700"\n                           checked', false);
    }

    public function test_treatment_list_filters_by_professional_and_status(): void
    {
        $other = User::factory()->professional()->create(['name' => 'Dr. Bruno']);
        Treatment::factory()->create(['patient_id' => $this->patient->id, 'professional_id' => $this->professional->id, 'treated_areas' => 'Glabela da Helena']);
        Treatment::factory()->create(['patient_id' => $this->patient->id, 'professional_id' => $other->id, 'treated_areas' => 'Lábios do Bruno']);
        Treatment::factory()->create(['patient_id' => $this->patient->id, 'professional_id' => $other->id, 'treated_areas' => 'Anulado do Bruno', 'voided_at' => now(), 'voided_by' => $other->id]);

        $this->actingAs($this->professional);

        $this->get('/atendimentos')->assertSee('Glabela da Helena')->assertSee('Lábios do Bruno')->assertDontSee('Anulado do Bruno');
        $this->get("/atendimentos?profissional={$other->id}")->assertDontSee('Glabela da Helena')->assertSee('Lábios do Bruno');
        $this->get('/atendimentos?situacao=anulados')->assertSee('Anulado do Bruno')->assertDontSee('Lábios do Bruno');
        $this->get('/atendimentos?situacao=x')->assertSessionHasErrors('situacao');
    }

    public function test_pagination_speaks_portuguese(): void
    {
        Patient::factory()->count(30)->create();

        $this->actingAs(User::factory()->create())->get('/pacientes')->assertOk()
            ->assertSee('aria-label="Paginação"', false)->assertSee('Próxima página')->assertDontSee('Pagination Navigation');
    }

    public function test_status_changes_from_the_agenda_are_still_audited(): void
    {
        $appointment = $this->appointment('2026-10-08 10:00');

        $this->actingAs(User::factory()->create())->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'no_show'])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', AuditAction::AppointmentStatusChanged->value)->sole();
        $this->assertSame(['scheduled', 'no_show'], [$log->metadata['from'], $log->metadata['to']]);
        $this->assertSame(AppointmentStatus::NoShow, $appointment->refresh()->status);
    }

    public function test_occupancy_sends_guests_to_login_lets_admins_in_and_lists_only_open_slots(): void
    {
        $url = '/agenda/ocupacao?data=2026-10-09&profissional='.$this->professional->id;

        $this->get($url)->assertRedirect('/login');
        $this->getJson($url)->assertUnauthorized();

        $open = $this->appointment('2026-10-09 09:00', ['procedure_id' => $this->procedure('Peeling')->id]);
        $this->appointment('2026-10-09 10:00', ['status' => AppointmentStatus::Confirmed, 'procedure_id' => $this->procedure('Limpeza de pele')->id]);
        $this->appointment('2026-10-09 11:00', ['status' => AppointmentStatus::Cancelled]);
        $this->appointment('2026-10-09 12:00', ['status' => AppointmentStatus::NoShow]);
        $archived = Patient::factory()->create();
        $this->appointment('2026-10-09 14:00', ['patient_id' => $archived->id]);
        $archived->delete();
        // Outro profissional e outro dia não entram.
        $this->appointment('2026-10-09 15:00', ['professional_id' => User::factory()->professional()->create()->id]);
        $this->appointment('2026-10-10 09:00');

        $this->actingAs(User::factory()->admin()->create())->getJson($url)->assertOk()
            ->assertExactJson(['slots' => [
                ['from' => '09:00', 'to' => '09:30', 'procedure' => 'Peeling'],
                ['from' => '10:00', 'to' => '10:30', 'procedure' => 'Limpeza de pele'],
            ]]);

        // Ao remarcar, o próprio agendamento não conta como ocupado.
        $this->getJson("{$url}&exceto={$open->id}")->assertOk()->assertJsonCount(1, 'slots')->assertJsonPath('slots.0.from', '10:00');

        $this->getJson('/agenda/ocupacao?data=2026-10-09')->assertUnprocessable()->assertJsonValidationErrors('profissional');
        $this->getJson("{$url}&exceto=abc")->assertUnprocessable()->assertJsonValidationErrors('exceto');
    }

    public function test_a_confirmed_appointment_offers_undoing_the_confirmation(): void
    {
        $confirmed = $this->appointment('2026-10-08 15:00', ['status' => AppointmentStatus::Confirmed]);

        $html = $this->actingAs(User::factory()->create())->get('/agenda?data=2026-10-08')->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form method="POST" action="'.preg_quote(route('appointments.status', $confirmed), '/').'"[^>]*>(?:(?!<\/form>).)*name="status" value="scheduled"(?:(?!<\/form>).)*Desfazer confirmação/s',
            $html,
            'Agendamento confirmado sem a ação "Desfazer confirmação".',
        );

        $this->patch("/agendamentos/{$confirmed->id}/situacao", ['status' => 'scheduled'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Scheduled, $confirmed->refresh()->status);
    }

    public function test_undoing_a_confirmation_is_denied_without_permission_to_manage_the_agenda(): void
    {
        $confirmed = $this->appointment('2026-10-08 15:00', ['status' => AppointmentStatus::Confirmed]);

        $this->patch("/agendamentos/{$confirmed->id}/situacao", ['status' => 'scheduled'])->assertRedirect('/login');

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::AgendaView]);
        $this->actingAs(User::factory()->create());

        $this->get('/agenda?data=2026-10-08')->assertOk()->assertDontSee('Desfazer confirmação');
        $this->patch("/agendamentos/{$confirmed->id}/situacao", ['status' => 'scheduled'])->assertForbidden();
        $this->assertSame(AppointmentStatus::Confirmed, $confirmed->refresh()->status);

        $this->actingAs(User::factory()->admin()->create())
            ->patch("/agendamentos/{$confirmed->id}/situacao", ['status' => 'scheduled'])->assertSessionHasNoErrors();
        $this->assertSame(AppointmentStatus::Scheduled, $confirmed->refresh()->status);
    }

    public function test_each_agenda_action_carries_the_view_and_filter_once(): void
    {
        $appointment = $this->appointment('2026-10-08 15:00');

        $html = $this->actingAs(User::factory()->create())
            ->get('/agenda?visao=semana&data=2026-10-08&profissional='.$this->professional->id)->assertOk()->getContent();

        preg_match_all('/<form method="POST" action="'.preg_quote(route('appointments.status', $appointment), '/').'"[^>]*>.*?<\/form>/s', $html, $forms);
        $this->assertNotEmpty($forms[0]);

        foreach ($forms[0] as $form) {
            $this->assertSame(1, substr_count($form, 'name="visao"'), "Formulário de situação com a visão repetida:\n{$form}");
            $this->assertSame(1, substr_count($form, 'name="profissional"'), "Formulário de situação com o filtro repetido:\n{$form}");
        }
    }

    public function test_appointments_take_ten_and_twenty_minute_durations(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/agendamentos/novo')->assertOk()->assertSee('<option value="10"', false)->assertSee('<option value="20"', false);

        foreach ([[10, '10:00', '10:10'], [20, '11:00', '11:20']] as [$minutes, $start, $endsAt]) {
            $this->post('/agendamentos', [
                'patient_id' => Patient::factory()->create()->id,
                'professional_id' => $this->professional->id,
                'scheduled_at' => "2026-10-09T{$start}",
                'duration_minutes' => $minutes,
            ])->assertSessionHasNoErrors();

            $created = Appointment::query()->latest('id')->first();
            $this->assertSame($endsAt, $created->ends_at->setTimezone('America/Sao_Paulo')->format('H:i'));
        }

        // Duração fora da lista continua recusada.
        $this->post('/agendamentos', [
            'patient_id' => $this->patient->id, 'professional_id' => $this->professional->id, 'scheduled_at' => '2026-10-09T14:00', 'duration_minutes' => 25,
        ])->assertSessionHasErrors('duration_minutes');
    }
}
