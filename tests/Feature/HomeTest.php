<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
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

    private function appointmentFor(string $patientName, string $localDateTime, array $attributes = []): Appointment
    {
        return Appointment::factory()->at($localDateTime)->create([
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
            ->assertSee('Atendimentos do dia')->assertSee('Pacientes cadastrados')->assertSee('Paciente de Hoje')->assertSee('Próximos agendamentos')
            ->assertDontSee('Faturamento do período')->assertDontSee('Atendimentos realizados')->assertDontSee('Estoque baixo')->assertDontSee('Próximos do vencimento');

        // Profissional: soma prontuário e estoque, mas não o financeiro.
        $this->actingAs(User::factory()->professional()->create())->get('/')
            ->assertSee('Atendimentos realizados')->assertSee('Estoque baixo')->assertSee('Próximos do vencimento')
            ->assertDontSee('Faturamento do período');

        $this->actingAs(User::factory()->admin()->create())->get('/')
            ->assertSee('Faturamento do período')->assertSee('Atendimentos realizados')->assertSee('Estoque baixo');
    }

    public function test_a_role_without_any_permission_gets_an_empty_home_and_no_data(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, []);
        $this->appointmentFor('Não Deve Aparecer', '2026-10-08 15:00');

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertOk()->assertSee('Seu perfil ainda não tem acesso a nenhum módulo')->assertDontSee('Não Deve Aparecer')->assertDontSee('Atendimentos do dia');
        $this->assertSame([], $response->viewData('totals'));
        $this->assertNull($response->viewData('todayAppointments'));
    }

    public function test_indicators_are_computed_for_the_current_month(): void
    {
        Patient::factory()->count(3)->create();
        Patient::factory()->create()->delete();

        $patient = Patient::first();
        Treatment::factory()->count(2)->create(['patient_id' => $patient->id, 'performed_at' => '2026-10-05 15:00:00']);
        Treatment::factory()->create(['patient_id' => $patient->id, 'performed_at' => '2026-09-20 15:00:00']);   // mês passado
        Treatment::factory()->create(['patient_id' => $patient->id]);                                                             // rascunho

        FinancialEntry::factory()->withInstallment('500', '2026-10-10')->withInstallment('250', '2026-11-10', number: 2)->create(['issued_on' => '2026-10-02']);
        FinancialEntry::factory()->withInstallment('999', '2026-09-10')->create(['issued_on' => '2026-09-30']);
        FinancialEntry::factory()->expense()->withInstallment('400', '2026-10-10')->create(['issued_on' => '2026-10-02']);

        $low = Product::factory()->create(['name' => 'Ácido hialurônico', 'minimum_stock' => 5]);
        ProductBatch::factory()->create(['product_id' => $low->id, 'quantity' => 2, 'expires_on' => '2028-01-01']);
        $ok = Product::factory()->create(['name' => 'Agulha 30G', 'minimum_stock' => 5]);
        ProductBatch::factory()->create(['product_id' => $ok->id, 'batch_code' => 'VENCE-LOGO', 'quantity' => 50, 'expires_on' => '2026-11-01']);
        ProductBatch::factory()->create(['product_id' => $ok->id, 'batch_code' => 'VENCIDO', 'quantity' => 1, 'expires_on' => '2026-10-01']);
        ProductBatch::factory()->create(['product_id' => $ok->id, 'batch_code' => 'ZERADO', 'quantity' => 0, 'expires_on' => '2026-10-20']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/');
        $totals = $response->viewData('totals');

        $this->assertSame(3, $totals['patients']);                 // arquivado não conta
        $this->assertSame(2, $totals['treatments']);     // só os feitos neste mês
        $this->assertEquals(750.0, $totals['revenue']);            // receitas lançadas em outubro, todas as parcelas
        $this->assertSame(1, $totals['low_stock']);
        $this->assertSame(2, $totals['expiring_batches']);         // o que vence logo e o vencido; o zerado não

        $response->assertSee('R$ 750,00')
            ->assertSee('Ácido hialurônico')->assertSee('2 de 5 un')
            ->assertSee('lote VENCE-LOGO')->assertSee('Vence em 01/11/2026')->assertSee('Venceu em 01/10/2026')->assertDontSee('ZERADO');
    }

    public function test_today_agenda_uses_the_clinic_calendar_day_not_utc(): void
    {
        // 23h30 em São Paulo já é dia 9 em UTC, mas ainda é hoje para a clínica.
        $this->appointmentFor('Fim de Hoje', '2026-10-08 23:30');
        $this->appointmentFor('Manhã de Hoje', '2026-10-08 09:00', ['status' => AppointmentStatus::Completed]);
        $this->appointmentFor('Cancelado de Hoje', '2026-10-08 10:00', ['status' => AppointmentStatus::Cancelled]);
        $this->appointmentFor('Ontem', '2026-10-07 23:45');

        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertSeeInOrder(['Agenda de hoje', 'Manhã de Hoje', 'Cancelado de Hoje', 'Fim de Hoje'])->assertDontSee('Ontem');
        // O cancelado aparece na lista, mas não conta nos atendimentos do dia.
        $response->assertSeeInOrder(['Atendimentos do dia', '2', '1 aguardando']);
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
        $this->actingAs(User::factory()->professional()->create())->get('/')
            ->assertSee('Confirmar presença')->assertSee('Registrar atendimento')->assertSee(e($registerUrl), false);

        $this->patch("/agendamentos/{$appointment->id}/situacao", ['status' => 'confirmed'])->assertSessionHasNoErrors();

        $this->get('/')->assertSee(e($registerUrl), false);
    }

    public function test_closed_appointments_show_the_right_actions(): void
    {
        $treatment = Treatment::factory()->create();
        $this->appointmentFor('Já Atendido', '2026-10-08 09:00', [
            'patient_id' => $treatment->patient_id, 'status' => AppointmentStatus::Completed, 'treatment_id' => $treatment->id,
        ]);
        $this->appointmentFor('Quem Faltou', '2026-10-08 10:00', ['status' => AppointmentStatus::NoShow]);

        $treatmentUrl = route('patients.treatments.show', [$treatment->patient_id, $treatment->id]);

        $this->actingAs(User::factory()->professional()->create())->get('/')
            ->assertSee('Atendido')->assertSee($treatmentUrl, false)->assertSee('Reabrir');

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
        // delas são as listas do diálogo "Registrar atendimento" (ver TreatmentDialogTest).
        $this->assertLessThanOrEqual(20, count(DB::getQueryLog()));
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
