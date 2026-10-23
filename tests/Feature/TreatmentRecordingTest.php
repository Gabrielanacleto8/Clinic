<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\TreatmentNote;
use App\Models\User;
use App\Services\RolePermissions;
use App\Services\StockLedger;
use App\Services\TreatmentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O que o registro de um atendimento faz no estoque e no financeiro, e o que
 * ainda se pode acrescentar a ele depois.
 */
class TreatmentRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Patient $patient;

    private ProductBatch $toxin;

    private ProductBatch $needle;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        Storage::fake('patient_photos');

        $this->author = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient = Patient::factory()->create(['name' => 'Marina Albuquerque']);
        $this->toxin = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Toxina botulínica 100 U'])->id, 'batch_code' => 'C1234', 'quantity' => 100, 'expires_on' => '2027-06-30',
        ]);
        $this->needle = ProductBatch::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Agulha 30G'])->id, 'batch_code' => 'AG01', 'quantity' => 10, 'expires_on' => null,
        ]);

        $this->url = "/pacientes/{$this->patient->id}/atendimentos";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->procedure('Toxina botulínica', ['price' => 1200])->id,
            'description' => 'Aplicados 30 U.',
            'products' => [
                ['product_batch_id' => $this->toxin->id, 'quantity' => '30'],
                ['product_batch_id' => $this->needle->id, 'quantity' => '2'],
            ],
            'amount' => '1.200,00',
            'payment_method' => PaymentMethod::CreditCard->value,
            'installments' => 3,
            'first_due_date' => '2026-10-08',
            'first_installment_paid' => '1',
            ...$overrides,
        ];
    }

    /**
     * Registra o atendimento como a autora e devolve o registro.
     */
    private function record(array $overrides = []): Treatment
    {
        $this->actingAs($this->author)->post($this->url, $this->payload($overrides))->assertSessionHasNoErrors();

        return Treatment::query()->latest('id')->firstOrFail();
    }

    private function assertNothingWasRecorded(): void
    {
        $this->assertSame(0, Treatment::count());
        $this->assertSame(0, DB::table('treatment_products')->count());
        $this->assertSame('100.000', $this->toxin->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, FinancialEntry::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::TreatmentCreated->value]);
    }

    /**
     * Dar o pagamento por recebido no registro exige a permissão de registrar pagamentos.
     */
    private function letProfessionalsReceivePayments(): void
    {
        app(RolePermissions::class)->sync(UserRole::Professional, [...Permission::defaultsFor(UserRole::Professional), Permission::FinanceView, Permission::FinanceManage]);
    }

    public function test_registering_deducts_stock_and_records_finance_at_once(): void
    {
        $this->letProfessionalsReceivePayments();

        $this->actingAs($this->author)->post($this->url, $this->payload())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Atendimento registrado. Estoque e financeiro foram atualizados.');

        $treatment = Treatment::sole();

        // Estoque: baixa por lote, ligada ao atendimento.
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame('8.000', $this->needle->refresh()->quantity);

        $movements = StockMovement::orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame([StockMovementType::Consumption, '-30.000', $treatment->id, $this->author->id], [
            $movements[0]->type, $movements[0]->quantity, $movements[0]->treatment_id, $movements[0]->user_id,
        ]);

        // Financeiro: receita do atendimento, ligada a paciente e atendimento.
        $entry = FinancialEntry::with('installments')->sole();
        $this->assertSame(FinancialEntryType::Income, $entry->type);
        $this->assertSame(FinancialCategory::Treatment, $entry->category);
        $this->assertSame('Atendimento: Toxina botulínica', $entry->description);
        $this->assertSame([$this->patient->id, $treatment->id], [$entry->patient_id, $entry->treatment_id]);
        $this->assertSame('2026-10-08', $entry->issued_on->format('Y-m-d'));
        $this->assertSame(['400.00', '400.00', '400.00'], $entry->installments->pluck('amount')->all());
        $this->assertSame(['2026-10-08', '2026-11-08', '2026-12-08'], $entry->installments->map(fn ($i) => $i->due_date->format('Y-m-d'))->all());
        // Cartão de crédito: as três parcelas ficam recebidas na data do atendimento.
        $this->assertSame(['2026-10-08', '2026-10-08', '2026-10-08'], $entry->installments->map(fn ($i) => $i->paid_at?->format('Y-m-d'))->all());

        $log = DB::table('audit_logs')->where('action', AuditAction::TreatmentCreated->value)->sole();
        $this->assertSame([$treatment->id, $this->author->id], [$log->auditable_id, $log->user_id]);
        $this->assertEquals(['patient_id' => $this->patient->id, 'products' => 2, 'charged' => true], json_decode($log->metadata, true));

        $this->get("{$this->url}/{$treatment->id}")->assertOk()->assertSee('Produtos utilizados')->assertSee('AG01');
    }

    public function test_revenue_belongs_to_the_day_the_treatment_was_performed(): void
    {
        // Atendimento da semana passada, registrado só hoje e já pago.
        $this->letProfessionalsReceivePayments();
        $this->record(['performed_at' => '2026-10-01T23:30', 'installments' => 1, 'first_due_date' => '2026-10-01']);

        $entry = FinancialEntry::with('installments')->sole();

        // 23h30 em São Paulo ainda é dia 1º, embora em UTC já seja dia 2.
        $this->assertSame('2026-10-01', $entry->issued_on->format('Y-m-d'));
        $this->assertSame('2026-10-01', $entry->installments[0]->paid_at->format('Y-m-d'));
    }

    public function test_a_treatment_without_charge_generates_no_financial_entry(): void
    {
        // Sem cobrança, forma de pagamento e parcelas nem são exigidas.
        $this->record(['amount' => '0', 'payment_method' => '', 'installments' => '', 'first_due_date' => '']);

        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
    }

    public function test_payment_details_are_required_when_there_is_a_charge(): void
    {
        $this->actingAs($this->author);

        $this->post($this->url, $this->payload(['payment_method' => '']))->assertSessionHasErrors('payment_method');
        $this->post($this->url, $this->payload(['payment_method' => 'cheque']))->assertSessionHasErrors('payment_method');
        $this->post($this->url, $this->payload(['installments' => 13]))->assertSessionHasErrors('installments');
        $this->post($this->url, $this->payload(['first_due_date' => '']))->assertSessionHasErrors('first_due_date');
        $this->post($this->url, $this->payload(['amount' => '-1']))->assertSessionHasErrors('amount');
        $this->post($this->url, $this->payload(['amount' => 'mil']))->assertSessionHasErrors('amount');
        $this->post($this->url, $this->payload(['amount' => '']))->assertSessionHasErrors('amount');

        $this->assertNothingWasRecorded();
    }

    public function test_insufficient_stock_blocks_the_registration_and_nothing_is_recorded(): void
    {
        // A toxina (primeiro item) tem saldo; a agulha (segundo) não.
        DB::table('product_batches')->where('id', $this->needle->id)->update(['quantity' => 1.5]);

        // O formulário volta com a mensagem e com o que foi digitado.
        $this->actingAs($this->author)->followingRedirects()->from("{$this->url}/novo")->post($this->url, $this->payload())
            ->assertOk()
            ->assertSee('Saldo insuficiente de Agulha 30G, lote AG01. Disponível: 1,5, necessário: 2.')
            ->assertSee('value="1.200,00"', false);

        // Tudo ou nada: o registro e a baixa da toxina, feitos antes do erro, foram desfeitos.
        $this->assertNothingWasRecorded();
    }

    public function test_expired_batches_and_inactive_products_cannot_be_used(): void
    {
        $expired = ProductBatch::factory()->create(['product_id' => $this->toxin->product_id, 'batch_code' => 'VENCIDO', 'quantity' => 10, 'expires_on' => '2026-10-07']);
        $inactive = ProductBatch::factory()->create(['product_id' => Product::factory()->create(['name' => 'Fora de Uso', 'is_active' => false])->id]);

        $this->actingAs($this->author);

        $this->get("{$this->url}/novo")->assertOk()->assertSee('Agulha 30G')->assertDontSee('Fora de Uso')->assertDontSee('VENCIDO');

        foreach ([$expired, $inactive] as $batch) {
            $this->post($this->url, $this->payload(['products' => [['product_batch_id' => $batch->id, 'quantity' => '1']]]))
                ->assertSessionHasErrors('products.0.product_batch_id');
        }

        $this->assertNothingWasRecorded();
    }

    public function test_the_recorder_itself_refuses_an_expired_batch(): void
    {
        // Mesmo sem passar pelo formulário, produto vencido não é aplicado em paciente.
        DB::table('product_batches')->where('id', $this->toxin->id)->update(['expires_on' => '2026-10-07']);

        $treatment = Treatment::factory()->make(['patient_id' => $this->patient->id, 'professional_id' => $this->author->id, 'created_by' => $this->author->id]);

        try {
            app(TreatmentRecorder::class)->record($treatment, [['product_batch_id' => $this->toxin->id, 'quantity' => '30.000']], null, null, $this->author);
            $this->fail('O registro deveria ter sido recusado.');
        } catch (ValidationException $exception) {
            $this->assertSame('O lote de Toxina botulínica 100 U, lote C1234 está vencido. Escolha outro lote.', $exception->validator->errors()->first('products'));
        }

        $this->assertNothingWasRecorded();
    }

    public function test_a_treatment_never_consumes_the_same_batch_twice(): void
    {
        $treatment = Treatment::factory()->create();
        $ledger = app(StockLedger::class);
        $ledger->consume($this->toxin, '30', $treatment, $this->author);

        try {
            $ledger->consume($this->toxin, '30', $treatment, $this->author);
            $this->fail('O banco deveria recusar o consumo em dobro.');
        } catch (UniqueConstraintViolationException) {
            // A segunda baixa é desfeita por inteiro: nem movimentação, nem saldo.
        }

        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_new_information_enters_a_treatment_as_an_immutable_note(): void
    {
        $colleague = User::factory()->professional()->create(['name' => 'Dr. Colega']);
        $treatmentUrl = "{$this->url}/{$this->record()->id}";
        $notesUrl = "{$treatmentUrl}/evolucoes";

        $this->post($notesUrl, ['body' => ''])->assertSessionHasErrors('body');
        $this->post($notesUrl, ['body' => str_repeat('a', 5001)])->assertSessionHasErrors('body');
        $this->actingAs(User::factory()->create())->post($notesUrl, ['body' => 'Recepção'])->assertForbidden();

        // Qualquer profissional registra a evolução, por exemplo no retorno.
        $this->actingAs($colleague)->post($notesUrl, ['body' => 'Retorno de 15 dias: resultado simétrico, sem queixas.'])
            ->assertRedirect($treatmentUrl)->assertSessionHasNoErrors();

        $note = TreatmentNote::sole();

        $this->assertSame($colleague->id, $note->user_id);
        $this->assertSame('Retorno de 15 dias: resultado simétrico, sem queixas.', $note->body);
        $this->assertStringNotContainsString('simétrico', DB::table('treatment_notes')->value('body'));
        $this->assertArrayNotHasKey('body', $note->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TreatmentNoteAdded->value, 'auditable_id' => $note->id, 'user_id' => $colleague->id]);

        $this->get($treatmentUrl)->assertSee('Evoluções e adendos')->assertSee('resultado simétrico')->assertSee('Dr. Colega');

        $this->expectException(\LogicException::class);
        $note->update(['body' => 'Reescrito']);
    }

    public function test_any_professional_adds_attachments_but_only_admin_removes_them(): void
    {
        $colleague = User::factory()->professional()->create();
        $photo = fn () => ['photos' => [UploadedFile::fake()->image('retorno.jpg', 60, 60)], 'stage' => 'after'];
        $treatment = $this->record();
        $photosUrl = "{$this->url}/{$treatment->id}/fotos";
        // Remover é pela rota de fotos do paciente.
        $photoUrl = fn ($attachment) => "/pacientes/{$treatment->patient_id}/fotos/{$attachment->id}";

        $this->actingAs(User::factory()->create())->post($photosUrl, $photo())->assertForbidden();

        // Um colega acrescenta a foto do retorno, mas não remove.
        $this->actingAs($colleague)->post($photosUrl, $photo())->assertSessionHasNoErrors();
        $attachment = $treatment->photos()->sole();

        $this->delete($photoUrl($attachment))->assertForbidden();
        $this->actingAs($this->author)->delete($photoUrl($attachment))->assertForbidden();
        $this->assertNotSoftDeleted($attachment);

        $this->actingAs(User::factory()->admin()->create())->delete($photoUrl($attachment))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($attachment);
    }

    public function test_financial_details_are_shown_only_to_who_can_see_finance(): void
    {
        $treatmentUrl = "{$this->url}/{$this->record()->id}";

        // O profissional registra a cobrança, mas não vê o financeiro.
        $this->get($treatmentUrl)->assertOk()->assertDontSee('Parcela 1/3')->assertDontSee('R$ 1.200,00');

        // Sem a permissão de registrar pagamentos, a caixa "já recebido" é ignorada e a cobrança fica pendente.
        $this->actingAs(User::factory()->admin()->create())->get($treatmentUrl)
            ->assertSee('R$ 1.200,00')->assertSee('Cartão de crédito')->assertSee('Parcela 1/3')->assertSee('Pendente');
    }

    public function test_stock_history_points_back_to_the_treatment(): void
    {
        $treatment = $this->record();

        $this->actingAs(User::factory()->admin()->create())->get("/estoque/{$this->toxin->product_id}")
            ->assertOk()
            ->assertSee('Atendimento nº '.$treatment->id)
            ->assertSee("{$this->url}/{$treatment->id}", false)
            ->assertSee('70 un');
    }
}
