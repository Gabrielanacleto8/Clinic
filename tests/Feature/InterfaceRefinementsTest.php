<?php

namespace Tests\Feature;

use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Models\Appointment;
use App\Models\User;
use App\Services\FinancialRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Telas vazias com a próxima ação, ações secundárias da agenda agrupadas e
 * faturamento com o recebido e o ano anterior.
 */
class InterfaceRefinementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_listings_offer_the_next_action(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/pacientes')->assertSee('Cadastrar o primeiro paciente');

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/atendimentos')->assertSee('Registrar o primeiro atendimento');
        $this->get('/procedimentos')->assertSee('Cadastrar procedimento');
    }

    public function test_secondary_appointment_actions_are_grouped_under_more(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 08:00', 'America/Sao_Paulo'));
        $appointment = Appointment::factory()->create(['scheduled_at' => CarbonImmutable::parse('2026-10-08 10:00', 'America/Sao_Paulo')->utc()]);

        $html = $this->actingAs(User::factory()->create())->get('/agenda?data=2026-10-08')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details class="group\/more">\s*<summary[^>]*>Mais.*?Remarcar.*?Cancelar.*?<\/details>/s', $html);
        $this->assertStringContainsString('id="ag-'.$appointment->id.'"', $html);
    }

    public function test_revenue_by_month_shows_received_and_the_year_before(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/Sao_Paulo'));
        $recorder = app(FinancialRecorder::class);
        $recorder->record(FinancialCategory::OtherIncome, 'Out/25', '400.00', PaymentMethod::Pix, 1, '2025-10-10', false, null, '2025-10-10');
        $recorder->record(FinancialCategory::OtherIncome, 'Out/26', '500.00', PaymentMethod::Pix, 1, '2026-10-10', true, null, '2026-10-10');

        $response = $this->actingAs(User::factory()->admin()->create())->get('/financeiro/comparativo')->assertOk();

        $this->assertSame(500.0, $response->viewData('months')['2026-10']->received);
        $this->assertSame(400.0, $response->viewData('months')['2026-10']->before->issued);
        $response->assertSee('+25%')->assertSee('vs Out/25');
    }
}
