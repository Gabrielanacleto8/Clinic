<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contas a receber, contas a pagar e inadimplência.
 */
class FinanceAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->admin()->create();
    }

    private function open(string $amount, string $dueDate, string $description, ?Patient $patient = null, bool $expense = false, ?string $paidAt = null): FinancialEntry
    {
        $factory = FinancialEntry::factory()->withInstallment($amount, $dueDate, $paidAt)->state(['patient_id' => $patient?->id]);

        return ($expense ? $factory->expense() : $factory)->create(['description' => $description]);
    }

    public function test_receivables_are_split_by_due_date(): void
    {
        $joana = Patient::factory()->create(['name' => 'Joana Prado']);
        $this->open('100', '2026-09-01', 'Vencida da Joana', $joana);
        $this->open('200', '2026-10-08', 'Vence hoje');
        $this->open('300', '2026-10-12', 'Semana que vem');
        $this->open('400', '2026-10-30', 'Fim do mês');
        $this->open('500', '2027-01-10', 'Ano que vem');
        $this->open('999', '2026-10-01', 'Já paga', paidAt: '2026-10-01');
        $this->open('888', '2026-10-01', 'Aluguel', expense: true);

        $response = $this->actingAs($this->admin)->get('/financeiro/a-receber')->assertOk()
            ->assertSee('Vencida da Joana')->assertSee('Joana Prado')->assertSee('Ano que vem')
            ->assertDontSee('Já paga')->assertDontSee('Aluguel');

        $this->assertEquals([
            'vencidas' => ['amount' => 100.0, 'count' => 1],
            'hoje' => ['amount' => 200.0, 'count' => 1],
            'semana' => ['amount' => 300.0, 'count' => 1],
            'mes' => ['amount' => 400.0, 'count' => 1],
            'depois' => ['amount' => 500.0, 'count' => 1],
        ], $response->viewData('totals'));

        $this->get('/financeiro/a-receber?faixa=semana')->assertSee('Semana que vem')->assertDontSee('Fim do mês');
        $this->get('/financeiro/a-receber?q=Joana')->assertSee('Vencida da Joana')->assertDontSee('Vence hoje');
        $this->get('/financeiro/a-receber?faixa=outra')->assertSessionHasErrors('faixa');

        $this->get('/financeiro/a-pagar')->assertOk()->assertSee('Aluguel')->assertDontSee('Vence hoje')->assertSee('data-dialog-open="installment-payment"', false);

        // Cada abertura da lista a receber é auditada, sem o termo buscado.
        $this->assertSame(3, AuditLog::where('action', AuditAction::FinanceViewed->value)->where('metadata->view', 'receivable')->count());
        $this->assertSame(1, AuditLog::where('metadata->view', 'receivable')->where('metadata->patient_filter', true)->count());
        $this->assertSame(0, AuditLog::where('metadata', 'like', '%Joana%')->count());
    }

    public function test_delinquency_groups_overdue_charges_by_patient_and_age(): void
    {
        $joana = Patient::factory()->create(['name' => 'Joana Prado']);
        $bruno = Patient::factory()->create(['name' => 'Bruno Lima']);
        $this->open('100', '2026-10-01', 'Joana 1', $joana);   // 7 dias
        $this->open('250', '2026-06-01', 'Joana 2', $joana);   // 129 dias
        $this->open('80', '2026-09-20', 'Bruno', $bruno);      // 18 dias
        $this->open('70', '2026-10-20', 'Ainda vai vencer', $bruno);
        $this->open('60', '2026-09-01', 'Sem paciente');

        $response = $this->actingAs($this->admin)->get('/financeiro/inadimplencia')->assertOk()
            ->assertSee('Joana Prado')->assertSee('Bruno Lima')->assertSee('R$ 60,00');

        $rows = collect($response->viewData('patients')->items())->keyBy('patient_id');
        $this->assertEqualsWithDelta(350.0, (float) $rows[$joana->id]->total, 0.001);
        $this->assertSame(129, (int) $rows[$joana->id]->oldest);
        $this->assertEqualsWithDelta(100.0, (float) $rows[$joana->id]->aging_0, 0.001);
        $this->assertEqualsWithDelta(250.0, (float) $rows[$joana->id]->aging_3, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $rows[$bruno->id]->total, 0.001);
        // Maior devedor primeiro.
        $this->assertSame($joana->id, (int) $response->viewData('patients')->items()[0]->patient_id);

        $summary = $response->viewData('summary');
        $this->assertEqualsWithDelta(490.0, (float) $summary->total, 0.001);
        $this->assertSame(2, (int) $summary->patients);

        $this->assertSame(1, AuditLog::where('action', AuditAction::FinanceViewed->value)->where('metadata->view', 'delinquency')->count());
    }

    public function test_only_who_sees_the_finance_opens_these_tabs(): void
    {
        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user);

            foreach (['/financeiro/a-receber', '/financeiro/a-pagar', '/financeiro/inadimplencia', '/financeiro/fluxo-de-caixa', '/financeiro/margem', '/financeiro/comissoes', '/financeiro/despesas-fixas'] as $url) {
                $this->get($url)->assertForbidden();
            }
        }
    }

    public function test_tabs_run_a_fixed_number_of_queries(): void
    {
        $this->actingAs($this->admin);

        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get($url)->assertOk();

            return count(DB::getQueryLog());
        };

        $this->open('10', '2026-09-01', 'Uma', Patient::factory()->create());
        $this->open('10', '2026-09-01', 'Despesa', expense: true);
        $urls = ['/financeiro/a-receber', '/financeiro/a-pagar', '/financeiro/inadimplencia', '/financeiro/fluxo-de-caixa', '/financeiro/conciliacao', '/financeiro/despesas-fixas'];
        $few = array_map($count, $urls);

        foreach (range(1, 8) as $index) {
            $this->open('10', '2026-09-0'.($index % 9 + 1), "Outra {$index}", Patient::factory()->create());
            $this->open('10', '2026-10-0'.($index % 9 + 1), "Despesa {$index}", expense: true);
        }

        $this->assertSame($few, array_map($count, $urls));
    }

    public function test_output_is_escaped_in_the_new_tabs(): void
    {
        $patient = Patient::factory()->create(['name' => '<img src=x onerror=alert(1)>']);
        $this->open('10', '2026-09-01', '<script>alert(2)</script>', $patient);
        FinancialEntry::factory()->withInstallment('10', '2026-10-08', '2026-10-08')->state(['payment_method' => 'pix', 'patient_id' => $patient->id])
            ->create(['description' => '<script>alert(3)</script>']);

        $this->actingAs($this->admin);

        foreach (['/financeiro/a-receber', '/financeiro/inadimplencia', '/financeiro/caixa', '/financeiro/conciliacao'] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('<img src=x onerror=alert(1)>', false)
                ->assertDontSee('<script>alert(2)</script>', false)
                ->assertDontSee('<script>alert(3)</script>', false);
        }
    }
}
