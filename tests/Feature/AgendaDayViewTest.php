<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Visão do dia da agenda: um profissional por coluna.
 */
class AgendaDayViewTest extends TestCase
{
    use RefreshDatabase;

    private User $helena;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'America/Sao_Paulo'));
        $this->helena = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->bruno = User::factory()->professional()->create(['name' => 'Dr. Bruno']);
    }

    private function appointment(User $professional, string $localStart, int $minutes, string $patient, AppointmentStatus $status = AppointmentStatus::Scheduled): Appointment
    {
        $appointment = new Appointment;
        $appointment->schedule(CarbonImmutable::parse($localStart, 'America/Sao_Paulo')->utc(), $minutes);
        $appointment->patient_id = Patient::factory()->create(['name' => $patient])->id;
        $appointment->professional_id = $professional->id;
        $appointment->procedure_id = $this->procedure('Avaliação')->id;
        $appointment->status = $status;
        $appointment->save();

        return $appointment;
    }

    public function test_day_view_shows_each_professional_in_a_column_with_the_blocks_in_place(): void
    {
        $this->appointment($this->helena, '2026-10-08 09:00', 60, 'Mariana Costa');
        $this->appointment($this->bruno, '2026-10-08 09:30', 30, 'João Pereira');
        $this->appointment($this->bruno, '2026-10-08 11:00', 30, 'Cancelada Silva', AppointmentStatus::Cancelled);

        $response = $this->actingAs(User::factory()->create())->get('/agenda?visao=dia&data=2026-10-08')->assertOk();
        $html = $response->getContent();

        $response->assertSee('aria-label="Dr. Bruno"', false)->assertSee('aria-label="Dra. Helena"', false);
        // Faixa padrão das 7h: 9h começa na linha 9 (8 linhas de 15 min) e ocupa 4.
        $this->assertMatchesRegularExpression('/row-start-9 row-span-4[^"]*"[^>]*>\s*<span[^>]*>09:00<\/span>\s*<span[^>]*>Mariana Costa/s', $html);
        $this->assertMatchesRegularExpression('/row-start-11 row-span-2[^"]*"[^>]*>\s*<span[^>]*>09:30<\/span>\s*<span[^>]*>João Pereira/s', $html);
        // O cancelado fica só na lista do dia, fora da grade.
        $this->assertStringNotContainsString('row-span-2 m-px', substr($html, (int) strpos($html, 'Cancelada Silva') - 400, 400));

        // Horário livre leva ao agendamento já preenchido.
        $response->assertSee(route('appointments.create', ['data' => '2026-10-08', 'hora' => '14:00', 'profissional' => $this->helena->id]));
    }

    public function test_day_view_widens_the_hours_for_an_early_appointment_and_respects_the_filter(): void
    {
        $this->appointment($this->helena, '2026-10-08 06:15', 30, 'Madrugadora');

        $this->actingAs($this->helena)->get('/agenda?visao=dia&data=2026-10-08')
            ->assertOk()
            ->assertSee('06:00')
            ->assertSee('aria-label="Dra. Helena"', false)
            // Quem atende abre a própria agenda: só a coluna dela.
            ->assertDontSee('aria-label="Dr. Bruno"', false);
    }

    public function test_day_view_keeps_a_constant_number_of_queries(): void
    {
        $this->appointment($this->helena, '2026-10-08 09:00', 30, 'Primeira');
        $this->appointment($this->bruno, '2026-10-08 09:00', 30, 'Segunda');
        $this->actingAs(User::factory()->create());

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/agenda?visao=dia&data=2026-10-08')->assertOk();

            return count(DB::getQueryLog());
        };

        // A primeira requisição do teste ainda lê as permissões; a medida vale a partir da segunda.
        $count();
        $before = $count();

        foreach (range(10, 16) as $hour) {
            $this->appointment($hour % 2 ? $this->helena : $this->bruno, "2026-10-08 {$hour}:00", 30, "Paciente {$hour}");
        }

        $this->assertSame($before, $count());
    }

    public function test_appointment_form_is_prefilled_from_the_grid_and_from_a_package(): void
    {
        $procedure = $this->procedure('Microagulhamento', ['duration_minutes' => 60]);

        $this->actingAs(User::factory()->create())
            ->get('/agendamentos/novo?'.http_build_query(['data' => '2026-10-09', 'hora' => '14:30', 'profissional' => $this->bruno->id, 'procedimento' => $procedure->id]))
            ->assertOk()
            ->assertSee('value="2026-10-09T14:30"', false)
            ->assertViewHas('appointment', fn (Appointment $appointment) => $appointment->professional_id === $this->bruno->id
                && $appointment->procedure_id === $procedure->id
                && $appointment->duration_minutes === 60);

        // Profissional que não atende não é aceito como sugestão.
        $this->get('/agendamentos/novo?profissional='.User::factory()->create()->id)
            ->assertViewHas('appointment', fn (Appointment $appointment) => $appointment->professional_id === null);
        $this->get('/agendamentos/novo?hora=25:99')->assertSessionHasErrors('hora');
    }

    public function test_actions_from_the_agenda_of_all_professionals_return_to_it(): void
    {
        $appointment = $this->appointment($this->helena, '2026-10-08 09:00', 30, 'Mariana Costa');

        $this->actingAs(User::factory()->create())
            ->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'confirmed', 'visao' => 'dia', 'profissional' => 'todos'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('agenda.index', ['visao' => 'dia', 'profissional' => 'todos', 'data' => '2026-10-08']).'#dia');
    }
}
