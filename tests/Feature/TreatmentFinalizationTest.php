<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\FinancialCategory;
use App\Enums\FinancialEntryType;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\TreatmentNote;
use App\Models\User;
use App\Services\StockLedger;
use App\Services\TreatmentFinalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TreatmentFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Patient $patient;

    private Treatment $treatment;

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

        $this->treatment = Treatment::factory()->create([
            'patient_id' => $this->patient->id,
            'professional_id' => $this->author->id,
            'created_by' => $this->author->id,
            'procedure_id' => $this->procedure('Toxina botulínica', ['price' => 1200])->id,
            'performed_at' => CarbonImmutable::parse('2026-10-08 12:00', 'America/Sao_Paulo')->utc(),
        ]);
        $this->treatment->products()->createMany([
            ['product_batch_id' => $this->toxin->id, 'quantity' => 30],
            ['product_batch_id' => $this->needle->id, 'quantity' => 2],
        ]);

        $this->url = "/pacientes/{$this->patient->id}/atendimentos/{$this->treatment->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(array $overrides = []): array
    {
        return [
            'amount' => '1.200,00',
            'payment_method' => PaymentMethod::CreditCard->value,
            'installments' => 3,
            'first_due_date' => '2026-10-08',
            'first_installment_paid' => '1',
            ...$overrides,
        ];
    }

    public function test_finalization_screen_shows_what_will_happen(): void
    {
        $this->actingAs($this->author)->get("{$this->url}/finalizar")
            ->assertOk()
            ->assertSee('Toxina botulínica 100 U')
            ->assertSee('Lote C1234')
            ->assertSee('30 un')
            ->assertSee('100 un')
            // Valor sugerido: o do catálogo de procedimentos.
            ->assertSee('value="1200,00"', false)
            ->assertDontSee('insuficiente');
    }

    public function test_finalizing_deducts_stock_records_finance_and_locks_the_treatment(): void
    {
        $appointment = Appointment::factory()->at('2026-10-08 11:30')->create(['patient_id' => $this->patient->id, 'treatment_id' => $this->treatment->id]);

        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment())
            ->assertRedirect($this->url)
            ->assertSessionHasNoErrors();

        // Atendimento: histórico permanente.
        $this->treatment->refresh();
        $this->assertSame(TreatmentStatus::Finalized, $this->treatment->status);
        $this->assertSame('2026-10-08 16:00:00', $this->treatment->finalized_at->format('Y-m-d H:i:s'));

        // Estoque: baixa por lote, ligada ao atendimento.
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame('8.000', $this->needle->refresh()->quantity);

        $movements = StockMovement::orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame([StockMovementType::Consumption, '-30.000', $this->treatment->id, $this->author->id], [
            $movements[0]->type, $movements[0]->quantity, $movements[0]->treatment_id, $movements[0]->user_id,
        ]);

        // Financeiro: receita do atendimento, ligada a paciente e atendimento.
        $entry = FinancialEntry::with('installments')->sole();
        $this->assertSame(FinancialEntryType::Income, $entry->type);
        $this->assertSame(FinancialCategory::Treatment, $entry->category);
        $this->assertSame('Atendimento: Toxina botulínica', $entry->description);
        $this->assertSame([$this->patient->id, $this->treatment->id], [$entry->patient_id, $entry->treatment_id]);
        $this->assertSame('2026-10-08', $entry->issued_on->format('Y-m-d'));
        $this->assertSame(['400.00', '400.00', '400.00'], $entry->installments->pluck('amount')->all());
        $this->assertSame(['2026-10-08', '2026-11-08', '2026-12-08'], $entry->installments->map(fn ($i) => $i->due_date->format('Y-m-d'))->all());
        $this->assertSame([true, false, false], $entry->installments->map(fn ($i) => $i->isPaid())->all());

        // Agenda: o agendamento de origem passa a atendido.
        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TreatmentFinalized->value, 'auditable_id' => $this->treatment->id, 'user_id' => $this->author->id]);

        $this->get($this->url)->assertOk()->assertSee('Finalizado')->assertSee('utilizados')->assertDontSee('Finalizar atendimento')->assertDontSee('Editar');
        $this->get("/pacientes/{$this->patient->id}")->assertDontSee('Em andamento');
    }

    public function test_a_treatment_without_charge_generates_no_financial_entry(): void
    {
        // Sem cobrança, forma de pagamento e parcelas nem são exigidas.
        $this->actingAs($this->author)->post("{$this->url}/finalizar", ['amount' => '0'])->assertSessionHasNoErrors();

        $this->assertTrue($this->treatment->refresh()->isFinalized());
        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
    }

    public function test_payment_details_are_required_when_there_is_a_charge(): void
    {
        $this->actingAs($this->author);

        $this->post("{$this->url}/finalizar", $this->payment(['payment_method' => '']))->assertSessionHasErrors('payment_method');
        $this->post("{$this->url}/finalizar", $this->payment(['payment_method' => 'cheque']))->assertSessionHasErrors('payment_method');
        $this->post("{$this->url}/finalizar", $this->payment(['installments' => 13]))->assertSessionHasErrors('installments');
        $this->post("{$this->url}/finalizar", $this->payment(['first_due_date' => '']))->assertSessionHasErrors('first_due_date');
        $this->post("{$this->url}/finalizar", $this->payment(['amount' => '-1']))->assertSessionHasErrors('amount');
        $this->post("{$this->url}/finalizar", $this->payment(['amount' => 'mil']))->assertSessionHasErrors('amount');
        $this->post("{$this->url}/finalizar", [])->assertSessionHasErrors('amount');

        $this->assertTrue($this->treatment->refresh()->isDraft());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, FinancialEntry::count());
    }

    public function test_insufficient_stock_blocks_the_finalization_and_nothing_is_recorded(): void
    {
        // A toxina (primeiro item) tem saldo; a agulha (segundo) não.
        DB::table('product_batches')->where('id', $this->needle->id)->update(['quantity' => 1.5]);

        $this->actingAs($this->author);

        $this->get("{$this->url}/finalizar")->assertSee('insuficiente');

        $this->from("{$this->url}/finalizar")->post("{$this->url}/finalizar", $this->payment())
            ->assertRedirect("{$this->url}/finalizar")
            ->assertSessionHasErrors(['products' => 'Saldo insuficiente de Agulha 30G, lote AG01. Disponível: 1,5, necessário: 2.']);

        // Tudo ou nada: a baixa da toxina, feita antes do erro, foi desfeita.
        $this->assertTrue($this->treatment->refresh()->isDraft());
        $this->assertSame('100.000', $this->toxin->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, FinancialEntry::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::TreatmentFinalized->value]);
    }

    public function test_an_expired_batch_blocks_the_finalization(): void
    {
        // O lote estava válido quando o rascunho foi salvo e venceu antes da finalização.
        DB::table('product_batches')->where('id', $this->toxin->id)->update(['expires_on' => '2026-10-07']);

        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment())
            ->assertSessionHasErrors(['products' => 'O lote de Toxina botulínica 100 U, lote C1234 está vencido. Troque o lote antes de finalizar.']);

        $this->assertTrue($this->treatment->refresh()->isDraft());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_an_inactive_product_blocks_the_finalization(): void
    {
        $this->toxin->product->update(['is_active' => false]);

        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment())->assertSessionHasErrors('products');

        $this->assertTrue($this->treatment->refresh()->isDraft());
        $this->assertSame('100.000', $this->toxin->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_batches_of_inactive_products_cannot_be_added_to_a_treatment(): void
    {
        $inactive = ProductBatch::factory()->create(['product_id' => Product::factory()->create(['name' => 'Fora de Uso', 'is_active' => false])->id]);

        $this->actingAs($this->author);

        $this->get("{$this->url}/editar")->assertOk()->assertSee('Agulha 30G')->assertDontSee('Fora de Uso');
        $this->put($this->url, [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->treatment->procedure_id,
            'description' => 'Aplicação.',
            'products' => [['product_batch_id' => $inactive->id, 'quantity' => '1']],
        ])->assertSessionHasErrors('products.0.product_batch_id');
    }

    public function test_finalization_screen_points_to_the_batch_that_expires_first(): void
    {
        $this->actingAs($this->author);
        $this->get("{$this->url}/finalizar")->assertOk()->assertDontSee('vence antes');

        // Outro lote da toxina, com saldo e vencimento anterior ao escolhido.
        $batch = fn (string $code, int $quantity, string $expiresOn) => ProductBatch::factory()->create([
            'product_id' => $this->toxin->product_id, 'batch_code' => $code, 'quantity' => $quantity, 'expires_on' => $expiresOn,
        ]);
        $batch('C0999', 20, '2026-12-31');
        // Vencido ou sem saldo não é sugerido.
        $batch('C0001', 20, '2026-10-01');
        $batch('C0002', 0, '2026-11-01');

        $this->get("{$this->url}/finalizar")->assertSee('O lote C0999 vence antes (31/12/2026).')->assertDontSee('C0001')->assertDontSee('C0002');

        // É um aviso: o lote registrado no atendimento continua sendo o baixado.
        $this->post("{$this->url}/finalizar", $this->payment())->assertSessionHasNoErrors();
        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
    }

    public function test_a_treatment_never_consumes_the_same_batch_twice(): void
    {
        $ledger = app(StockLedger::class);
        $ledger->consume($this->toxin, '30', $this->treatment, $this->author);

        try {
            $ledger->consume($this->toxin, '30', $this->treatment, $this->author);
            $this->fail('O banco deveria recusar o consumo em dobro.');
        } catch (UniqueConstraintViolationException) {
            // A segunda baixa é desfeita por inteiro: nem movimentação, nem saldo.
        }

        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_a_treatment_cannot_be_finalized_twice(): void
    {
        $this->actingAs($this->author);

        $this->post("{$this->url}/finalizar", $this->payment())->assertSessionHasNoErrors();
        $this->post("{$this->url}/finalizar", $this->payment())->assertForbidden();
        $this->get("{$this->url}/finalizar")->assertForbidden();

        // Mesmo chamando o serviço direto (dois envios simultâneos), a segunda finalização é recusada.
        try {
            app(TreatmentFinalizer::class)->finalize($this->treatment, $this->author, null);
            $this->fail('A segunda finalização deveria ter sido recusada.');
        } catch (ValidationException $exception) {
            $this->assertSame('Este atendimento já foi finalizado.', $exception->validator->errors()->first('treatment'));
        }

        $this->assertSame('70.000', $this->toxin->refresh()->quantity);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(1, FinancialEntry::count());
    }

    public function test_only_the_responsible_professional_or_an_admin_can_finalize(): void
    {
        $this->actingAs(User::factory()->professional()->create())->post("{$this->url}/finalizar", $this->payment())->assertForbidden();
        $this->actingAs(User::factory()->create())->post("{$this->url}/finalizar", $this->payment())->assertForbidden();

        $this->assertTrue($this->treatment->refresh()->isDraft());

        $this->actingAs(User::factory()->admin()->create())->post("{$this->url}/finalizar", $this->payment())->assertSessionHasNoErrors();

        $this->assertTrue($this->treatment->refresh()->isFinalized());
    }

    public function test_a_finalized_treatment_cannot_be_changed_or_discarded(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment());

        $change = [
            'performed_at' => '2026-10-08T12:00', 'procedure_id' => $this->treatment->procedure_id, 'description' => 'Adulterado',
            'products' => [['product_batch_id' => $this->toxin->id, 'quantity' => '1']],
        ];

        // Nem o autor nem o administrador alteram o que foi finalizado.
        foreach ([$this->author, $admin] as $user) {
            $this->actingAs($user);
            $this->get("{$this->url}/editar")->assertForbidden();
            $this->put($this->url, $change)->assertForbidden();
            $this->delete($this->url)->assertForbidden();
        }

        $this->treatment->refresh();

        $this->assertNotSame('Adulterado', $this->treatment->description);
        $this->assertNotSoftDeleted($this->treatment);
        $this->assertSame(['30.000', '2.000'], $this->treatment->products()->orderBy('id')->pluck('quantity')->all());
    }

    public function test_new_information_enters_a_finalized_treatment_as_an_immutable_note(): void
    {
        $colleague = User::factory()->professional()->create(['name' => 'Dr. Colega']);
        $notesUrl = "{$this->url}/evolucoes";

        // Enquanto é rascunho, edita-se o próprio registro: não há evolução.
        $this->actingAs($this->author)->post($notesUrl, ['body' => 'Cedo demais'])->assertForbidden();
        $this->get($this->url)->assertDontSee('Evoluções e adendos');

        $this->post("{$this->url}/finalizar", $this->payment());

        $this->post($notesUrl, ['body' => ''])->assertSessionHasErrors('body');
        $this->post($notesUrl, ['body' => str_repeat('a', 5001)])->assertSessionHasErrors('body');
        $this->actingAs(User::factory()->create())->post($notesUrl, ['body' => 'Recepção'])->assertForbidden();

        // Qualquer profissional registra a evolução, por exemplo no retorno.
        $this->actingAs($colleague)->post($notesUrl, ['body' => 'Retorno de 15 dias: resultado simétrico, sem queixas.'])
            ->assertRedirect($this->url)->assertSessionHasNoErrors();

        $note = TreatmentNote::sole();

        $this->assertSame($colleague->id, $note->user_id);
        $this->assertSame('Retorno de 15 dias: resultado simétrico, sem queixas.', $note->body);
        $this->assertStringNotContainsString('simétrico', DB::table('treatment_notes')->value('body'));
        $this->assertArrayNotHasKey('body', $note->toArray());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TreatmentNoteAdded->value, 'auditable_id' => $note->id, 'user_id' => $colleague->id]);

        $this->get($this->url)->assertSee('Evoluções e adendos')->assertSee('resultado simétrico')->assertSee('Dr. Colega');

        $this->expectException(\LogicException::class);
        $note->update(['body' => 'Reescrito']);
    }

    public function test_attachments_can_be_added_to_a_finalized_treatment_but_only_admin_removes_them(): void
    {
        $colleague = User::factory()->professional()->create();
        $photo = fn () => ['photos' => [UploadedFile::fake()->image('retorno.jpg', 60, 60)], 'stage' => 'after'];

        // Rascunho: colega não anexa.
        $this->actingAs($colleague)->post("{$this->url}/fotos", $photo())->assertForbidden();

        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment());

        // Finalizado: colega acrescenta a foto do retorno, mas não remove.
        $this->actingAs($colleague)->post("{$this->url}/fotos", $photo())->assertSessionHasNoErrors();
        $attachment = $this->treatment->photos()->sole();

        $this->delete("{$this->url}/fotos/{$attachment->id}")->assertForbidden();
        $this->actingAs($this->author)->delete("{$this->url}/fotos/{$attachment->id}")->assertForbidden();
        $this->assertNotSoftDeleted($attachment);

        $this->actingAs(User::factory()->admin()->create())->delete("{$this->url}/fotos/{$attachment->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($attachment);
    }

    public function test_financial_details_are_shown_only_to_who_can_see_finance(): void
    {
        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment());

        // O profissional registra a cobrança, mas não vê o financeiro.
        $this->get($this->url)->assertOk()->assertDontSee('Parcela 1/3')->assertDontSee('R$ 1.200,00');

        $this->actingAs(User::factory()->admin()->create())->get($this->url)
            ->assertSee('R$ 1.200,00')->assertSee('Cartão de crédito')->assertSee('Parcela 1/3')->assertSee('Pago')->assertSee('Pendente');
    }

    public function test_stock_history_points_back_to_the_treatment(): void
    {
        $this->actingAs($this->author)->post("{$this->url}/finalizar", $this->payment());

        $this->actingAs(User::factory()->admin()->create())->get("/estoque/{$this->toxin->product_id}")
            ->assertOk()
            ->assertSee('Atendimento nº '.$this->treatment->id)
            ->assertSee($this->url, false)
            ->assertSee('70 un');
    }
}
