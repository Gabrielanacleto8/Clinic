<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Permission;
use App\Enums\ProductUnit;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use App\Services\StockLedger;
use App\Services\StockReceiver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Quinta-feira, 13h em São Paulo (16h UTC).
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    private function appointmentFor(string $patientName, string $localDateTime, array $attributes = [], int $minutes = 30): Appointment
    {
        return Appointment::factory()->at($localDateTime, $minutes)->create([
            'patient_id' => Patient::factory()->create(['name' => $patientName])->id,
            ...$attributes,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_each_role_sees_only_the_indicators_it_has_access_to(): void
    {
        $this->appointmentFor('Paciente de Hoje', '2026-10-08 15:00');

        // Recepção: agenda e pacientes.
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('Quinta-feira, 8 de outubro de 2026')
            ->assertSee('Agendamentos de hoje')->assertSee('Paciente de Hoje')->assertSee('Próximos agendamentos')->assertSee('Pendentes de registro')
            ->assertDontSee('Atendimentos de hoje')->assertDontSee('Alertas de estoque');

        // Profissional: soma prontuário e estoque.
        $this->actingAs(User::factory()->professional()->create())->get('/')
            ->assertSee('Atendimentos de hoje')->assertSee('Alertas de estoque')->assertSee('Unidades a descartar');

        $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertSee('Agendamentos de hoje')->assertSee('Atendimentos de hoje')->assertSee('Alertas de estoque');
    }

    public function test_home_shows_no_money_even_to_whoever_sees_the_finances(): void
    {
        FinancialEntry::factory()->withInstallment('750', '2026-10-10')->create(['issued_on' => '2026-10-02']);
        FinancialEntry::factory()->withInstallment('320', '2026-10-05')->create(['issued_on' => '2026-10-05'])
            ->installments()->update(['paid_at' => '2026-10-05']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/');

        $response->assertOk()
            ->assertDontSee('Faturamento')->assertDontSee('Recebido')
            ->assertDontSee('750,00')->assertDontSee('320,00');
        $this->assertSame(['treatments', 'low_stock', 'expiring_batches', 'units_to_discard'], array_keys($response->viewData('totals')));
    }

    public function test_a_role_without_any_permission_gets_an_empty_home_and_no_data(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, []);
        $this->appointmentFor('Não Deve Aparecer', '2026-10-08 15:00');

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk()->assertSee('Seu perfil ainda não tem acesso a nenhum módulo')->assertDontSee('Não Deve Aparecer')->assertDontSee('Agendamentos de hoje');
        $this->assertSame([], $response->viewData('totals'));
        $this->assertNull($response->viewData('todayAppointments'));
    }

    public function test_indicators_count_today_and_the_stock_alerts(): void
    {
        $admin = User::factory()->admin()->create();
        $patient = Patient::factory()->create();
        Treatment::factory()->count(2)->create(['patient_id' => $patient->id, 'performed_at' => '2026-10-08 14:00:00']);       // 11h em São Paulo
        Treatment::factory()->create(['patient_id' => $patient->id, 'performed_at' => '2026-10-09 02:30:00']);                  // 23h30 de hoje em São Paulo
        Treatment::factory()->create(['patient_id' => $patient->id, 'performed_at' => '2026-10-08 14:00:00', 'voided_at' => '2026-10-08 15:00:00']);
        Treatment::factory()->create(['patient_id' => $patient->id, 'performed_at' => '2026-10-07 15:00:00']);                  // ontem

        $low = Product::factory()->create(['name' => 'Ácido hialurônico', 'minimum_stock' => 5]);
        ProductBatch::factory()->create(['product_id' => $low->id, 'quantity' => 2, 'expires_on' => '2028-01-01']);
        $ok = Product::factory()->create(['name' => 'Agulha 30G', 'minimum_stock' => 5]);
        ProductBatch::factory()->create(['product_id' => $ok->id, 'batch_code' => 'VENCE-LOGO', 'quantity' => 50, 'expires_on' => '2026-11-01']);
        ProductBatch::factory()->create(['product_id' => $ok->id, 'batch_code' => 'VENCIDO', 'quantity' => 1, 'expires_on' => '2026-10-01']);
        ProductBatch::factory()->create(['product_id' => $ok->id, 'batch_code' => 'ZERADO', 'quantity' => 0, 'expires_on' => '2026-10-20']);

        // Unidade aberta de produto que ficou inativo: não vai mais para paciente.
        $toxin = Product::factory()->create(['name' => 'Toxina', 'unit' => ProductUnit::ToxinUnit, 'unit_content' => 100, 'multi_patient_use' => true]);
        $batch = app(StockReceiver::class)->receive($toxin->id, ['batch_code' => 'TX1', 'quantity' => '200', 'expires_on' => '2027-06-30', 'unit_cost' => null], $admin)->batch;
        app(StockLedger::class)->openUnit($batch, $admin);
        $toxin->update(['is_active' => false]);

        // O administrador também atende: "todos" tira o filtro dos próprios atendimentos.
        $response = $this->actingAs($admin)->get('/?todos=1');
        $totals = $response->viewData('totals');

        $this->assertSame(3, $totals['treatments']);           // os de hoje no fuso da clínica, sem o anulado
        $this->assertSame(1, $totals['low_stock']);
        $this->assertSame(2, $totals['expiring_batches']);     // o que vence logo e o vencido; o zerado não
        $this->assertSame(1, $totals['units_to_discard']);

        $response->assertSeeInOrder(['Atendimentos de hoje', '3'])
            ->assertSeeInOrder(['Alertas de estoque', '4', 'Baixo, vencendo ou a descartar'])
            ->assertSee('Ácido hialurônico')->assertSee('2 de 5 un')
            ->assertSee('lote VENCE-LOGO')->assertSee('01/11/2026')->assertSee('Vencido')->assertSee('01/10/2026')->assertDontSee('ZERADO')
            ->assertSee('lote TX1')->assertSee('Unidade é de um produto inativo.');
    }

    public function test_treatments_of_today_follow_the_professional_filter(): void
    {
        $professional = User::factory()->professional()->create();
        $patient = Patient::factory()->create();
        Treatment::factory()->create(['patient_id' => $patient->id, 'professional_id' => $professional->id, 'performed_at' => '2026-10-08 14:00:00']);
        Treatment::factory()->count(2)->create(['patient_id' => $patient->id, 'performed_at' => '2026-10-08 14:00:00']);

        $this->actingAs($professional);
        $this->assertSame(1, $this->get('/')->viewData('totals')['treatments']);
        $this->assertSame(3, $this->get('/?todos=1')->viewData('totals')['treatments']);
    }

    public function test_stock_alerts_say_so_when_there_is_nothing_to_resolve(): void
    {
        $this->actingAs(User::factory()->professional()->create())->get('/')
            ->assertSee('Nada a resolver')
            ->assertSee('Nenhum produto abaixo do mínimo.')
            ->assertSee('Nenhuma unidade aberta aguardando descarte.');
    }

    public function test_today_agenda_uses_the_clinic_calendar_day_not_utc(): void
    {
        // 23h30 em São Paulo já é dia 9 em UTC, mas ainda é hoje para a clínica.
        $this->appointmentFor('Fim de Hoje', '2026-10-08 23:30');
        $this->appointmentFor('Manhã de Hoje', '2026-10-08 09:00', ['status' => AppointmentStatus::Completed]);
        $this->appointmentFor('Cancelado de Hoje', '2026-10-08 10:00', ['status' => AppointmentStatus::Cancelled]);
        $this->appointmentFor('Ontem', '2026-10-07 23:45');

        $response = $this->actingAs(User::factory()->create())->get('/');

        // O de ontem, ainda em aberto, aparece como pendente de registro, não na agenda de hoje.
        $response->assertSeeInOrder(['Pendentes de registro', 'Ontem', 'Agenda de hoje', 'Manhã de Hoje', 'Cancelado de Hoje', 'Fim de Hoje']);
        // O cancelado aparece na lista, mas não conta nos agendamentos do dia.
        $response->assertSeeInOrder(['Agendamentos de hoje', '2', '1 em aberto']);
    }

    public function test_upcoming_appointments_lists_the_next_open_ones_after_today(): void
    {
        $this->appointmentFor('Hoje Não Entra', '2026-10-08 18:00');
        $this->appointmentFor('Cancelado Amanhã', '2026-10-09 08:00', ['status' => AppointmentStatus::Cancelled]);
        $this->appointmentFor('Confirmado Amanhã', '2026-10-09 09:00', ['status' => AppointmentStatus::Confirmed]);

        foreach (range(1, 6) as $day) {
            $this->appointmentFor("Futuro {$day}", '2026-10-1'.$day.' 10:00');
        }

        $upcoming = $this->actingAs(User::factory()->create())->get('/')->viewData('upcomingAppointments');

        $this->assertSame(
            ['Confirmado Amanhã', 'Futuro 1', 'Futuro 2', 'Futuro 3', 'Futuro 4'],
            $upcoming->map(fn (Appointment $appointment) => $appointment->patient->name)->all(),
        );
    }

    public function test_only_clinical_staff_can_register_a_treatment_from_home(): void
    {
        $appointment = $this->appointmentFor('Paciente Agendado', '2026-10-08 15:00');
        $registerUrl = route('patients.treatments.create', [$appointment->patient_id, 'agendamento' => $appointment->id]);

        $this->actingAs(User::factory()->create())->get('/')
            ->assertSee('Remarcar')->assertSee('Faltou')->assertSee('Confirmar presença')
            ->assertDontSee('Registrar atendimento')->assertDontSee($registerUrl, false);

        // O profissional registra direto do agendamento, com ou sem a presença confirmada.
        // Por padrão vê só a própria agenda: o agendamento é de outro profissional.
        $this->actingAs(User::factory()->professional()->create())->get('/')
            ->assertSee('Registrar atendimento')->assertDontSee(e($registerUrl), false)->assertSee('Ver todos os profissionais');
        $this->get('/?todos=1')
            ->assertSee('Confirmar presença')->assertSee('Registrar atendimento')->assertSee(e($registerUrl), false)->assertSee('Só os meus');

        $this->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'confirmed'])->assertSessionHasNoErrors();

        $this->get('/?todos=1')->assertSee(e($registerUrl), false);
    }

    public function test_closed_appointments_show_the_right_actions(): void
    {
        $treatment = Treatment::factory()->create();
        $this->appointmentFor('Já Atendido', '2026-10-08 09:00', [
            'patient_id' => $treatment->patient_id, 'status' => AppointmentStatus::Completed, 'treatment_id' => $treatment->id,
        ]);
        $this->appointmentFor('Quem Faltou', '2026-10-08 10:00', ['status' => AppointmentStatus::NoShow]);

        $treatmentUrl = route('patients.treatments.show', [$treatment->patient_id, $treatment->id]);

        // Quem atende vê só a própria agenda por padrão; aqui a de todos.
        // Falta de hoje pode ser reaberta; de dia anterior, não.
        $this->actingAs(User::factory()->professional()->create())->get('/?todos=1')
            ->assertSee('Atendido')->assertSee($treatmentUrl, false)->assertSee('Reabrir');
        $this->appointmentFor('Faltou Ontem', '2026-10-07 10:00', ['status' => AppointmentStatus::NoShow]);
        $this->get('/agenda?data=2026-10-07&profissional=todos')->assertSee('Faltou Ontem')->assertDontSee('Reabrir');

        // A recepção vê a situação, mas não o link para o prontuário.
        $this->actingAs(User::factory()->create())->get('/')->assertSee('Atendido')->assertDontSee($treatmentUrl, false);
    }

    public function test_home_does_not_run_one_query_per_item(): void
    {
        foreach (range(1, 8) as $hour) {
            $this->appointmentFor("Paciente {$hour}", '2026-10-08 '.str_pad((string) (8 + $hour), 2, '0', STR_PAD_LEFT).':00');
            $this->appointmentFor("Futuro {$hour}", '2026-10-09 '.str_pad((string) (8 + $hour), 2, '0', STR_PAD_LEFT).':00');
        }

        Product::factory()->count(6)->create(['minimum_stock' => 5])
            ->each(fn (Product $product) => ProductBatch::factory()->create(['product_id' => $product->id, 'quantity' => 1, 'expires_on' => '2026-10-20']));

        $this->actingAs(User::factory()->admin()->create());
        DB::enableQueryLog();

        $this->get('/')->assertOk();

        // 16 agendamentos e 6 produtos em tela, com número fixo de consultas. Quatro
        // delas são as listas do diálogo "Registrar atendimento" (ver TreatmentDialogTest);
        // pendentes de registro e retornos a agendar somam uma lista e uma contagem cada.
        $this->assertLessThanOrEqual(25, count(DB::getQueryLog()));
    }

    public function test_home_with_pending_registrations_returns_and_alerts_keeps_a_fixed_number_of_queries(): void
    {
        $professional = User::factory()->professional()->create();
        $round = 0;

        // Cada rodada soma, para o mesmo profissional: agendamento de hoje, pendente de
        // registro, retorno sem agendamento, próximo agendamento e produto em alerta.
        $populate = function (int $count) use ($professional, &$round): void {
            foreach (range(1, $count) as $_) {
                $round++;
                $minute = str_pad((string) ($round * 2 % 60), 2, '0', STR_PAD_LEFT);
                $hour = str_pad((string) (8 + intdiv($round, 30)), 2, '0', STR_PAD_LEFT);
                $this->appointmentFor("Hoje {$round}", "2026-10-08 {$hour}:{$minute}", ['professional_id' => $professional->id], 1);
                $this->appointmentFor("Pendente {$round}", '2026-10-0'.(1 + $round % 6)." {$hour}:{$minute}", ['professional_id' => $professional->id], 1);
                $this->appointmentFor("Futuro {$round}", "2026-10-09 {$hour}:{$minute}", ['professional_id' => $professional->id], 1);
                Treatment::factory()->create([
                    'patient_id' => Patient::factory()->create(['name' => "Retorno {$round}"])->id,
                    'professional_id' => $professional->id,
                    'performed_at' => '2026-09-20 15:00:00',
                    'return_date' => '2026-10-1'.($round % 9),
                ]);
                $product = Product::factory()->create(['minimum_stock' => 5]);
                ProductBatch::factory()->create(['product_id' => $product->id, 'quantity' => 1, 'expires_on' => '2026-10-20']);
            }
        };

        $count = function (string $url) use ($professional): int {
            $this->actingAs($professional);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $populate(1);
        $count('/');
        $few = ['own' => $count('/'), 'all' => $count('/?todos=1')];

        $populate(7);
        $many = ['own' => $count('/'), 'all' => $count('/?todos=1')];

        $this->get('/')->assertSee('Pendentes de registro')->assertSee('Retornos previstos sem agendamento');
        $this->assertSame($few, $many);
    }

    public function test_output_is_escaped(): void
    {
        $this->appointmentFor('<script>alert(1)</script>', '2026-10-08 15:00', [
            'procedure_id' => $this->procedure('<b>negrito</b>')->id,
            'notes' => '<img src=x onerror=alert(2)>',
        ]);
        Product::factory()->create(['name' => '<i>produto</i>', 'minimum_stock' => 5]);

        $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>negrito</b>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<i>produto</i>', false);
    }

    public function test_home_is_the_first_module_in_the_sidebar_for_everyone(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);

        $html = $this->actingAs(User::factory()->create())->get('/')->getContent();

        $this->assertSame(1, preg_match('/<nav[^>]*aria-label="Módulos do sistema".*?<\/nav>/s', $html, $matches));
        $nav = $matches[0];

        // Independe do desenho da barra: só exige o link, o destaque e a ordem.
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('home'), '/').'"[^>]*aria-current="page"/', $nav);
        $this->assertLessThan(strpos($nav, 'Pacientes'), strpos($nav, 'Início'));
        // Sem permissão de agenda, o módulo não aparece no menu.
        $this->assertStringNotContainsString(route('agenda.index'), $nav);
    }
}
