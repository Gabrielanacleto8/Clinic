<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\User;
use App\Support\AreaChart;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_scale_starts_at_zero_and_ends_on_a_round_value(): void
    {
        $chart = new AreaChart(['A', 'B'], ['in' => [14200.0, 9100.0], 'out' => [9600.0, 0.0]]);

        $this->assertSame([20000.0, 15000.0, 10000.0, 5000.0, 0.0], $chart->ticks());
        $this->assertSame(25.0, $chart->xPercent(0));
        $this->assertSame(75.0, $chart->xPercent(1));
        $this->assertSame(50.0, $chart->yPercent(10000.0));
        $this->assertSame('M250,87 L750,163.5', $chart->line('in'));
        $this->assertSame('M250,87 L750,163.5 L750,300 L250,300 Z', $chart->area('in'));

        // Sem movimento, a escala não divide por zero.
        $this->assertSame([1000.0, 750.0, 500.0, 250.0, 0.0], (new AreaChart(['A'], ['in' => [0.0]]))->ticks());
    }

    public function test_axis_values_are_short(): void
    {
        $this->assertSame('R$ 0', AreaChart::compactMoney(0));
        $this->assertSame('R$ 750', AreaChart::compactMoney(750));
        $this->assertSame('R$ 2,5 mil', AreaChart::compactMoney(2500));
        $this->assertSame('R$ 20 mil', AreaChart::compactMoney(20000));
        $this->assertSame('R$ 1,2 mi', AreaChart::compactMoney(1_200_000));
    }

    public function test_overview_draws_received_and_paid_expenses_of_the_last_six_months(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        FinancialEntry::factory()->withInstallment('1200', '2026-10-05', '2026-10-05')->create(['issued_on' => '2026-10-05']);
        FinancialEntry::factory()->withInstallment('900', '2026-04-05', '2026-04-05')->create(['issued_on' => '2026-04-05']); // fora da janela
        FinancialEntry::factory()->expense()->withInstallment('300', '2026-08-03', '2026-08-03')->create(['issued_on' => '2026-08-03'])
            ->installments()->update(['reconciled_on' => '2026-09-02', 'fee' => '15.00']);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/financeiro')->assertOk();

        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_keys($response->viewData('flow')));
        preg_match('/data-points="([^"]*)"/', $response->getContent(), $match);
        $points = json_decode(html_entity_decode($match[1]), true);

        $this->assertCount(6, $points);
        $this->assertSame('Outubro de 2026', $points[5]['label']);
        $this->assertSame(['R$ 1.200,00', 'R$ 0,00'], $points[5]['values']);
        $this->assertSame(['R$ 0,00', 'R$ 300,00'], $points[3]['values']);
        $this->assertSame(['R$ 0,00', 'R$ 15,00'], $points[4]['values']);  // taxa no mês do extrato
        $response->assertSee('Receitas e despesas')->assertSee('Maio de 2026 a Outubro de 2026')->assertSee('R$ 1,5 mil');
    }
}
