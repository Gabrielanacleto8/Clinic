<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Services\FinancialRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Planilha do financeiro para o contador.
 */
class FinanceExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/Sao_Paulo'));
    }

    private function record(string $description, string $total, int $installments = 1, ?Patient $patient = null, FinancialCategory $category = FinancialCategory::OtherIncome): void
    {
        app(FinancialRecorder::class)->record($category, $description, $total, PaymentMethod::Pix, $installments, '2026-10-10', false, null, '2026-10-10', $patient);
    }

    public function test_exports_the_filtered_installments_without_patient_data(): void
    {
        $patient = Patient::factory()->create(['name' => 'Mariana Costa']);
        $this->record('Venda de kit', '300.00', 2, $patient);
        $this->record('Aluguel', '1500.00', category: FinancialCategory::Rent);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/financeiro/exportar?mes=2026-10');

        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=financeiro-2026-10-01-a-2026-10-31.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}\"Vencimento\";\"Pagamento\";\"Tipo\"", $csv);
        // Ligado a paciente, a descrição (texto livre) não sai.
        $this->assertStringContainsString('"10/10/2026";"";"Receita";"Outras receitas";"Receita de paciente";"Pix";"1/2";"150,00";"Vencido"', $csv);
        $this->assertStringNotContainsString('Venda de kit', $csv);
        $this->assertStringContainsString('"Aluguel e condomínio";"Aluguel";"Pix";"1/1";"1500,00"', $csv);
        // A parcela de novembro fica fora do mês filtrado.
        $this->assertSame(1, substr_count($csv, '"Receita de paciente"'));
        $this->assertStringNotContainsString('Mariana', $csv);

        $log = AuditLog::where('action', AuditAction::FinanceExported->value)->sole();
        $this->assertSame(2, $log->metadata['rows']);
    }

    public function test_formulas_typed_in_a_description_are_neutralized(): void
    {
        $this->record('=HYPERLINK("http://evil.test")', '10.00');

        $csv = $this->actingAs(User::factory()->admin()->create())->get('/financeiro/exportar?mes=2026-10')->streamedContent();

        $this->assertStringContainsString('"\'=HYPERLINK(""http://evil.test"")"', $csv);
    }

    public function test_only_who_sees_the_finance_exports_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/financeiro/exportar')->assertForbidden();
        $this->actingAs(User::factory()->professional()->create())->get('/financeiro/exportar')->assertForbidden();
        $this->get('/financeiro')->assertForbidden();

        $this->assertSame(0, AuditLog::where('action', AuditAction::FinanceExported->value)->count());
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/financeiro/exportar?situacao=tudo')->assertSessionHasErrors('situacao');
    }
}
