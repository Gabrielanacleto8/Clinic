<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array{string, string} Rótulos das abas principais e das subabas, na ordem da tela.
     */
    private function tabs(string $html): array
    {
        preg_match('/<nav[^>]*aria-label="Seções do financeiro"[^>]*>(.*?)<\/nav>/s', $html, $main);
        preg_match('/<nav[^>]*aria-label="Abas de [^"]*"[^>]*>(.*?)<\/nav>/s', $html, $sub);
        $labels = fn (?string $nav) => $nav === null ? '' : implode(' | ', array_map('trim', array_map('strip_tags', explode('</a>', trim($nav)))));

        return [trim($labels($main[1] ?? null), ' |'), trim($labels($sub[1] ?? null), ' |')];
    }

    public function test_the_ten_sections_are_grouped_in_four_main_tabs_with_subtabs(): void
    {
        $this->actingAs($this->admin);

        $pages = [
            '/financeiro' => ['Visão geral', ''],
            '/financeiro/a-receber' => ['Movimentações', 'A receber | A pagar | Inadimplência | Despesas fixas | Comissões'],
            '/financeiro/comissoes' => ['Movimentações', 'A receber | A pagar | Inadimplência | Despesas fixas | Comissões'],
            '/financeiro/fluxo-de-caixa' => ['Caixa', 'Fluxo de caixa | Caixa do dia | Conciliação'],
            '/financeiro/conciliacao' => ['Caixa', 'Fluxo de caixa | Caixa do dia | Conciliação'],
            '/financeiro/comparativo' => ['Análises', 'Comparativo mensal | Margem por procedimento | Margem e comissão por profissional'],
            '/financeiro/margem?agrupar=profissional' => ['Análises', 'Comparativo mensal | Margem por procedimento | Margem e comissão por profissional'],
        ];

        foreach ($pages as $url => [$group, $subtabs]) {
            $html = $this->get($url)->assertOk()->getContent();
            [$main, $sub] = $this->tabs($html);

            $this->assertSame('Visão geral | Movimentações | Caixa | Análises', $main, $url);
            $this->assertSame($subtabs, $sub, $url);
            $this->assertMatchesRegularExpression('/<a [^>]*aria-current="(true|page)"[^>]*>'.$group.'<\/a>/', $html, $url);
            // As abas principais não rolam: nada de overflow nelas.
            $this->assertDoesNotMatchRegularExpression('/<nav[^>]*overflow-x-auto[^>]*aria-label="Seções do financeiro"/', $html);
        }

        // A subaba atual acompanha o agrupamento da margem.
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Margem e comissão por profissional</', $this->get('/financeiro/margem?agrupar=profissional')->getContent());
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>Margem por procedimento</', $this->get('/financeiro/margem')->getContent());
    }

    public function test_tabs_follow_the_permissions(): void
    {
        // Só o caixa do dia: nenhuma aba, o menu leva direto à tela.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::FinanceReceive, Permission::FinanceCash]);
        $html = $this->actingAs(User::factory()->create())->get('/financeiro/caixa')->assertOk()->getContent();
        $this->assertStringNotContainsString('Seções do financeiro', $html);

        // Só leitura: Caixa sem a conciliação, que é de quem gerencia.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::FinanceView]);
        [$main, $sub] = $this->tabs($this->actingAs(User::factory()->create())->get('/financeiro/fluxo-de-caixa')->assertOk()->getContent());
        $this->assertSame('Visão geral | Movimentações | Caixa | Análises', $main);
        $this->assertSame('Fluxo de caixa | Caixa do dia', $sub);
    }

    public function test_monthly_comparison_sums_revenue_expenses_and_fees_against_the_year_before(): void
    {
        FinancialEntry::factory()->withInstallment('1000', '2026-10-05', '2026-10-06')->create(['issued_on' => '2026-10-05']);
        FinancialEntry::factory()->withInstallment('300', '2026-11-05')->create(['issued_on' => '2026-10-05']);
        FinancialEntry::factory()->expense()->withInstallment('200', '2026-10-03', '2026-10-03')->create(['issued_on' => '2026-10-03']);
        FinancialEntry::factory()->withInstallment('800', '2025-10-05', '2025-10-05')->create(['issued_on' => '2025-10-05'])
            ->installments()->update(['reconciled_on' => '2025-10-07', 'fee' => '20.00']);
        FinancialEntry::factory()->withInstallment('500', '2026-09-10', '2026-09-10')->create(['issued_on' => '2026-09-10'])
            ->installments()->update(['reconciled_on' => '2026-10-02', 'fee' => '15.00']);

        $response = $this->actingAs($this->admin)->get('/financeiro/comparativo')->assertOk();
        $months = $response->viewData('months');

        $this->assertCount(12, $months);
        $this->assertSame('2025-11', array_key_first($months));
        $october = $months['2026-10'];
        $this->assertSame([1300.0, 1000.0, 200.0, 15.0, 785.0], [$october->issued, $october->received, $october->expenses, $october->fees, $october->result]);
        $this->assertSame([800.0, 800.0, 20.0, 780.0], [$october->before->issued, $october->before->received, $october->before->fees, $october->before->result]);
        $this->assertSame(500.0, $months['2026-09']->received);
        $response->assertSee('+63%')->assertSee('vs Out/25')->assertSee('Novembro de 2025 a Outubro de 2026');

        $this->get('/financeiro/comparativo?mes=2026-09&meses=6')->assertOk()->assertViewHas('months', fn (array $months) => array_keys($months) === ['2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09']);
        $this->get('/financeiro/comparativo?meses=7')->assertSessionHasErrors('meses');
        $this->get('/financeiro/comparativo?mes=2026-13')->assertSessionHasErrors('mes');
    }

    public function test_monthly_comparison_access_is_denied_without_finance_view(): void
    {
        $this->get('/financeiro/comparativo')->assertRedirect('/login');

        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user)->get('/financeiro/comparativo')->assertForbidden();
        }

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::FinanceReceive, Permission::FinanceCash]);
        $this->actingAs(User::factory()->create())->get('/financeiro/comparativo')->assertForbidden();
    }

    public function test_monthly_comparison_runs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get('/financeiro/comparativo')->assertOk();

            return count(DB::getQueryLog());
        };

        $empty = $count();
        foreach (range(1, 8) as $day) {
            FinancialEntry::factory()->withInstallment('100', "2026-0{$day}-10", "2026-0{$day}-10")->create(['issued_on' => "2026-0{$day}-10"]);
        }

        $this->assertSame($empty, $count());
    }
}
