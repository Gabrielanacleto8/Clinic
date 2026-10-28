<?php

namespace Tests\Feature;

use App\Enums\AttachmentType;
use App\Enums\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->patient = Patient::factory()->create();
        $this->url = "/pacientes/{$this->patient->id}";
    }

    private function treatment(array $attributes = []): Treatment
    {
        return Treatment::factory()->create(['patient_id' => $this->patient->id, ...$attributes]);
    }

    /**
     * Anexo gravado direto no banco. O arquivo em si não importa para a listagem.
     * Sem atendimento, é uma foto avulsa do paciente, com data própria.
     */
    private function attachment(?Treatment $treatment, array $attributes = []): PatientAttachment
    {
        $attachment = new PatientAttachment;
        $attachment->forceFill([
            'patient_id' => $treatment?->patient_id ?? $this->patient->id, 'treatment_id' => $treatment?->id, 'taken_on' => $treatment ? null : '2026-01-15',
            'type' => AttachmentType::Photo, 'stage' => 'before', 'caption' => 'Frontal',
            'path' => 'x/'.uniqid().'.enc', 'thumbnail_path' => 'x/'.uniqid().'.enc', 'width' => 100, 'height' => 100, 'size' => 10,
            ...$attributes,
        ])->save();

        return $attachment;
    }

    public function test_tabs_follow_the_role_permissions(): void
    {
        // Recepção: o cadastro, a agenda, o financeiro do paciente (ela recebe) e os orçamentos, sem o prontuário.
        $reception = $this->actingAs(User::factory()->create())->get($this->url);
        $reception->assertOk()->assertDontSee('Produtos utilizados')->assertDontSee('Evoluções')->assertSee('Agendamentos');
        $this->assertSame(['agendamentos', 'financeiro', 'orcamentos'], array_keys($reception->viewData('tabs')));

        // Profissional: histórico clínico e agenda, sem o financeiro.
        $response = $this->actingAs(User::factory()->professional()->create())->get($this->url);
        $response->assertSee('Produtos utilizados')->assertSee('Fotos')->assertSee('Evoluções');
        $this->assertSame(['atendimentos', 'produtos', 'fotos', 'evolucoes', 'agendamentos', 'orcamentos'], array_keys($response->viewData('tabs')));

        // Pedir a aba financeira sem permissão cai na aba padrão.
        $this->get("{$this->url}?aba=financeiro")->assertOk()->assertViewHas('tab', 'atendimentos')->assertDontSee('A vencer');
        $this->get("{$this->url}?aba=inexistente")->assertOk()->assertViewHas('tab', 'atendimentos');

        $admin = $this->actingAs(User::factory()->admin()->create())->get("{$this->url}?aba=financeiro");
        $admin->assertOk()->assertViewHas('tab', 'financeiro')->assertSee('A vencer');
        $this->assertSame(['atendimentos', 'produtos', 'fotos', 'evolucoes', 'agendamentos', 'financeiro', 'orcamentos'], array_keys($admin->viewData('tabs')));
    }

    public function test_products_tab_lists_what_was_applied_in_the_patients_treatments(): void
    {
        $toxin = ProductBatch::factory()->create(['product_id' => Product::factory()->create(['name' => 'Toxina 100 U'])->id, 'batch_code' => 'C1234', 'expires_on' => '2027-06-30']);
        $discardedOnly = ProductBatch::factory()->create(['product_id' => Product::factory()->create(['name' => 'Só no Descartado'])->id]);
        $foreign = ProductBatch::factory()->create(['product_id' => Product::factory()->create(['name' => 'De Outro Paciente'])->id]);

        $this->treatment(['procedure_id' => $this->procedure('Toxina botulínica')->id, 'performed_at' => '2026-10-01 15:00:00'])
            ->products()->create(['product_batch_id' => $toxin->id, 'quantity' => 30]);
        // Rascunho descartado no tempo em que o atendimento tinha rascunho: nunca foi aplicado.
        $discarded = $this->treatment();
        $discarded->products()->create(['product_batch_id' => $discardedOnly->id, 'quantity' => 1]);
        $discarded->delete();
        Treatment::factory()->create()->products()->create(['product_batch_id' => $foreign->id, 'quantity' => 1]);

        $this->actingAs(User::factory()->professional()->create())->get("{$this->url}?aba=produtos")
            ->assertOk()
            ->assertSee('Toxina 100 U')->assertSee('C1234')->assertSee('val. 30/06/2027')->assertSee('30 un')->assertSee('01/10/2026')->assertSee('Toxina botulínica')
            ->assertDontSee('Só no Descartado')->assertDontSee('De Outro Paciente');
    }

    public function test_photos_tab_gathers_the_photos_of_every_treatment_and_the_loose_ones(): void
    {
        // 23:30 de 10/03 na clínica, já 11/03 em UTC: a ordem segue o dia da clínica.
        $first = $this->treatment(['procedure_id' => $this->procedure('Toxina botulínica')->id, 'performed_at' => '2026-03-11 02:30:00']);
        $second = $this->treatment(['procedure_id' => $this->procedure('Preenchimento labial')->id, 'performed_at' => '2026-09-10 15:00:00']);
        $discarded = $this->treatment();

        $shown = [
            $this->attachment($first, ['caption' => 'Antes frontal']),
            $this->attachment($second, ['stage' => 'after', 'caption' => 'Depois perfil']),
            $this->attachment(null, ['caption' => 'Retroativa', 'taken_on' => '2026-03-11']),
        ];
        $removed = $this->attachment($first, ['caption' => 'Removida']);
        $removed->delete();
        $document = $this->attachment($first, ['type' => AttachmentType::Document, 'caption' => 'Termo em PDF', 'stage' => null, 'thumbnail_path' => null]);
        $ofDiscarded = $this->attachment($discarded, ['caption' => 'De registro descartado']);
        $discarded->delete();
        $foreign = $this->attachment(Treatment::factory()->create(), ['caption' => 'De outro paciente']);

        $response = $this->actingAs(User::factory()->professional()->create())->get("{$this->url}?aba=fotos");

        $response->assertOk()->assertSee('Antes · Antes frontal')->assertSee('Depois · Depois perfil')->assertSee('Preenchimento labial')
            ->assertSee('Antes · Retroativa')->assertSee('11/03/2026')->assertSee('10/03/2026')->assertSee('Foto avulsa')
            // Da mais recente à mais antiga, pelo dia do atendimento no fuso da clínica ou pela data da foto.
            ->assertSeeInOrder(['Depois perfil', 'Retroativa', 'Antes frontal'])
            ->assertDontSee('Removida')->assertDontSee('Termo em PDF')->assertDontSee('De registro descartado')->assertDontSee('De outro paciente');

        foreach ($shown as $photo) {
            $response->assertSee("{$this->url}/fotos/{$photo->id}?miniatura=1", false);
        }
    }

    public function test_photos_tab_runs_a_fixed_number_of_queries(): void
    {
        $treatment = $this->treatment();
        foreach (range(1, 3) as $index) {
            $this->attachment($treatment, ['caption' => "Do atendimento {$index}"]);
            $this->attachment(null, ['caption' => "Avulsa {$index}"]);
        }

        $this->actingAs(User::factory()->professional()->create());
        DB::enableQueryLog();

        $this->get("{$this->url}?aba=fotos")->assertOk()->assertSee('Do atendimento 3')->assertSee('Avulsa 3');

        // Permissões, paciente, auditoria, criador e ficha, o cartão de agenda
        // (próximos, faltas e retorno previsto), contagem e página da galeria,
        // atendimentos e procedimentos: nada por foto.
        $this->assertSame(11, count(DB::getQueryLog()));
    }

    public function test_the_record_is_read_only_and_only_accepts_loose_photos(): void
    {
        $this->treatment();

        $this->actingAs(User::factory()->professional()->create())->get("{$this->url}?aba=atendimentos")
            ->assertOk()->assertDontSee('Registrar atendimento')->assertDontSee('atendimentos/novo', false);

        $this->get("{$this->url}?aba=fotos")->assertOk()->assertSee('Adicionar fotos avulsas')->assertSee('name="taken_on"', false);
    }

    public function test_notes_tab_lists_the_evolutions_newest_first(): void
    {
        $author = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $treatment = $this->treatment(['procedure_id' => $this->procedure('Toxina botulínica')->id]);

        $treatment->notes()->create(['body' => 'Primeira evolução: edema leve.', 'user_id' => $author->id]);
        $treatment->notes()->create(['body' => 'Segunda evolução: edema resolvido.', 'user_id' => $author->id]);
        Treatment::factory()->create()->notes()->create(['body' => 'De outro paciente.', 'user_id' => $author->id]);

        $this->actingAs($author)->get("{$this->url}?aba=evolucoes")
            ->assertOk()
            ->assertSeeInOrder(['Segunda evolução: edema resolvido.', 'Primeira evolução: edema leve.'])
            ->assertSee('Dra. Helena')->assertSee('Toxina botulínica de')
            ->assertDontSee('De outro paciente.');
    }

    public function test_finance_tab_shows_the_patient_financial_history_and_totals(): void
    {
        $treatment = $this->treatment();

        FinancialEntry::factory()
            ->withInstallment('400', '2026-10-08', '2026-10-08')
            ->withInstallment('400', '2026-11-08', number: 2)
            ->withInstallment('400', '2026-09-01', number: 3)
            ->create(['category' => FinancialCategory::Treatment, 'patient_id' => $this->patient->id, 'treatment_id' => $treatment->id, 'description' => 'Atendimento: Toxina botulínica']);
        FinancialEntry::factory()->withInstallment('999', '2026-10-08', '2026-10-08')->create(['description' => 'De Outro Paciente', 'patient_id' => Patient::factory()->create()->id]);
        FinancialEntry::factory()->withInstallment('555', '2026-10-08')->create(['description' => 'Sem Paciente']);

        $response = $this->actingAs(User::factory()->admin()->create())->get("{$this->url}?aba=financeiro");

        $response->assertOk()
            ->assertSee('Atendimento: Toxina botulínica')->assertSee('Parcela 1/3')->assertSee('Pago')->assertSee('Pendente')->assertSee('Vencido')
            ->assertSee(route('patients.treatments.show', [$this->patient, $treatment]), false)
            ->assertDontSee('De Outro Paciente')->assertDontSee('Sem Paciente');

        $totals = $response->viewData('financialTotals');

        $this->assertEquals(400.0, $totals->paid);
        // Vencido e a vencer separados, para a cobrança.
        $this->assertEquals(800.0, $totals->overdue + $totals->upcoming);
    }

    public function test_only_the_open_tab_is_queried(): void
    {
        $this->treatment();
        $this->actingAs(User::factory()->admin()->create());

        DB::enableQueryLog();
        $this->get($this->url)->assertOk();
        // O saldo pendente do cabeçalho é uma soma só; o resto do financeiro fica para a aba dele.
        $queries = implode("\n", array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query) => ! str_contains($query, 'coalesce(sum(i.amount)')));

        $this->assertStringContainsString('`treatments`', $queries);
        foreach (['financial_installments', 'treatment_notes', 'treatment_products', 'product_batches'] as $table) {
            $this->assertStringNotContainsString($table, $queries, "A aba de atendimentos consultou {$table}.");
        }
    }

    public function test_tab_links_are_kept_in_the_pagination(): void
    {
        $treatment = $this->treatment();

        foreach (range(1, 16) as $index) {
            $treatment->notes()->create(['body' => "Evolução {$index}", 'user_id' => null]);
        }

        $this->actingAs(User::factory()->professional()->create())->get("{$this->url}?aba=evolucoes")
            ->assertSee('aba=evolucoes&amp;page=2', false)->assertSee('Evolução 16')->assertDontSee('Evolução 1<', false);
    }
}
