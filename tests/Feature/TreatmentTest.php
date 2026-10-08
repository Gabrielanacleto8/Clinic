<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TreatmentTest extends TestCase
{
    use RefreshDatabase;

    private ProductBatch $toxin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->toxin = $this->batch('Toxina botulínica 100 U', 'C1234', 300, '2027-06-30');
    }

    private function batch(string $product, string $code, float $quantity, ?string $expiresOn): ProductBatch
    {
        return ProductBatch::factory()->create([
            'product_id' => Product::query()->where('name', $product)->value('id') ?? Product::factory()->create(['name' => $product])->id,
            'batch_code' => $code,
            'quantity' => $quantity,
            'expires_on' => $expiresOn,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'performed_at' => '2026-10-01T14:30',
            'procedure_id' => $this->procedure('Toxina botulínica')->id,
            'treated_areas' => 'Glabela e frontal',
            'assessment' => 'Rugas dinâmicas moderadas.',
            'description' => 'Aplicados 20 U na glabela e 10 U no frontal.',
            'post_care' => 'Não deitar por 4 horas.',
            'complications' => '',
            'return_date' => '2026-10-15',
            'products' => [
                ['product_batch_id' => $this->toxin->id, 'quantity' => '30'],
                ['product_batch_id' => '', 'quantity' => ''],
            ],
            ...$overrides,
        ];
    }

    public function test_guests_and_reception_cannot_reach_treatments(): void
    {
        $treatment = Treatment::factory()->create();
        $base = "/pacientes/{$treatment->patient_id}/atendimentos";

        $this->get("{$base}/{$treatment->id}")->assertRedirect('/login');
        $this->post($base, $this->payload())->assertRedirect('/login');
        $this->post("{$base}/{$treatment->id}/finalizar", ['amount' => '0'])->assertRedirect('/login');

        $this->actingAs(User::factory()->create());

        $this->get("{$base}/novo")->assertForbidden();
        $this->post($base, $this->payload())->assertForbidden();
        $this->get("{$base}/{$treatment->id}")->assertForbidden();
        $this->get("{$base}/{$treatment->id}/editar")->assertForbidden();
        $this->put("{$base}/{$treatment->id}", $this->payload())->assertForbidden();
        $this->delete("{$base}/{$treatment->id}")->assertForbidden();
        $this->get("{$base}/{$treatment->id}/finalizar")->assertForbidden();
        $this->post("{$base}/{$treatment->id}/finalizar", ['amount' => '0'])->assertForbidden();
        $this->post("{$base}/{$treatment->id}/evolucoes", ['body' => 'Tentativa'])->assertForbidden();

        $this->assertSame(1, Treatment::count());
        $this->assertTrue($treatment->refresh()->isDraft());
    }

    public function test_professional_registers_a_treatment_as_a_draft_that_touches_neither_stock_nor_finance(): void
    {
        $user = User::factory()->professional()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($user);
        $this->get("/pacientes/{$patient->id}/atendimentos/novo")->assertOk()->assertSee('Toxina botulínica 100 U')->assertSee('lote C1234');

        $this->post("/pacientes/{$patient->id}/atendimentos", $this->payload())->assertSessionHasNoErrors();

        $treatment = Treatment::with('products')->firstOrFail();

        $this->assertSame(TreatmentStatus::Draft, $treatment->status);
        $this->assertNull($treatment->finalized_at);
        $this->assertSame($patient->id, $treatment->patient_id);
        $this->assertSame($user->id, $treatment->professional_id);
        $this->assertSame($user->id, $treatment->created_by);
        // Digitado no horário de São Paulo (UTC-3), gravado em UTC.
        $this->assertSame('2026-10-01 17:30:00', $treatment->performed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15', $treatment->return_date->format('Y-m-d'));
        $this->assertSame('Rugas dinâmicas moderadas.', $treatment->assessment);
        $this->assertNull($treatment->complications);

        // A linha em branco do formulário é ignorada.
        $this->assertCount(1, $treatment->products);
        $this->assertSame($this->toxin->id, $treatment->products[0]->product_batch_id);
        $this->assertSame('30.000', $treatment->products[0]->quantity);

        // Rascunho não baixa estoque nem gera lançamento.
        $this->assertSame('300.000', $this->toxin->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, FinancialEntry::count());

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TreatmentCreated->value, 'user_id' => $user->id, 'auditable_id' => $treatment->id]);

        $this->get("/pacientes/{$patient->id}/atendimentos/{$treatment->id}")
            ->assertOk()
            ->assertSee('Em andamento')
            ->assertSee('Toxina botulínica')
            ->assertSee('01/10/2026')
            ->assertSee('14:30')
            ->assertSee('C1234')
            ->assertSee('30 un')
            ->assertSee('Finalizar atendimento');

        $this->get("/pacientes/{$patient->id}")->assertSee('Toxina botulínica')->assertSee('Glabela e frontal')->assertSee('Em andamento');
    }

    public function test_clinical_text_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->professional()->create())
            ->post("/pacientes/{$patient->id}/atendimentos", $this->payload(['complications' => 'Hematoma leve']));

        $raw = DB::table('treatments')->first();

        $this->assertStringNotContainsString('dinâmicas', $raw->assessment);
        $this->assertStringNotContainsString('glabela', $raw->description);
        $this->assertStringNotContainsString('deitar', $raw->post_care);
        $this->assertStringNotContainsString('Hematoma', $raw->complications);

        $serialized = Treatment::first()->toArray();

        foreach (['assessment', 'description', 'post_care', 'complications'] as $field) {
            $this->assertArrayNotHasKey($field, $serialized);
        }
    }

    public function test_professional_cannot_attribute_a_treatment_to_someone_else(): void
    {
        $user = User::factory()->professional()->create();
        $other = User::factory()->professional()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($user)
            ->post("/pacientes/{$patient->id}/atendimentos", $this->payload([
                'professional_id' => $other->id, 'created_by' => $other->id, 'patient_id' => 999, 'status' => 'finalized', 'finalized_at' => '2026-10-01 00:00:00',
            ]))
            ->assertSessionHasNoErrors();

        $treatment = Treatment::firstOrFail();

        $this->assertSame($user->id, $treatment->professional_id);
        $this->assertSame($user->id, $treatment->created_by);
        $this->assertSame($patient->id, $treatment->patient_id);
        // A situação nunca vem do formulário.
        $this->assertTrue($treatment->isDraft());
        $this->assertNull($treatment->finalized_at);
    }

    public function test_admin_can_register_on_behalf_of_an_active_professional_only(): void
    {
        $admin = User::factory()->admin()->create();
        $professional = User::factory()->professional()->create();
        $patient = Patient::factory()->create();
        $url = "/pacientes/{$patient->id}/atendimentos";

        $this->actingAs($admin);

        $this->post($url, $this->payload(['professional_id' => $professional->id]))->assertSessionHasNoErrors();
        $this->assertSame($professional->id, Treatment::firstOrFail()->professional_id);
        $this->assertSame($admin->id, Treatment::firstOrFail()->created_by);

        foreach ([User::factory()->create(), User::factory()->professional()->inactive()->create()] as $invalid) {
            $this->post($url, $this->payload(['professional_id' => $invalid->id]))->assertSessionHasErrors('professional_id');
        }

        $this->assertSame(1, Treatment::count());
    }

    public function test_treatment_data_is_validated(): void
    {
        $patient = Patient::factory()->create();
        $expired = $this->batch('Anestésico', 'VENCIDO', 10, '2026-10-07');
        $url = "/pacientes/{$patient->id}/atendimentos";

        $this->actingAs(User::factory()->professional()->create());

        $this->post($url, $this->payload([
            'performed_at' => 'ontem',
            'procedure_id' => '',
            'description' => '',
            'assessment' => str_repeat('a', 5001),
            'return_date' => '15/10/2026',
        ]))->assertSessionHasErrors(['performed_at', 'procedure_id', 'description', 'assessment', 'return_date']);

        $this->post($url, $this->payload(['procedure_id' => Procedure::factory()->inactive()->create()->id]))->assertSessionHasErrors('procedure_id');

        // Produtos: lote vencido, lote inexistente, quantidade ausente ou zerada, lote repetido e linhas demais.
        $this->post($url, $this->payload(['products' => [['product_batch_id' => $expired->id, 'quantity' => '1']]]))
            ->assertSessionHasErrors(['products.0.product_batch_id' => 'Este lote não existe ou está vencido.']);
        $this->post($url, $this->payload(['products' => [['product_batch_id' => 999999, 'quantity' => '1']]]))->assertSessionHasErrors('products.0.product_batch_id');
        $this->post($url, $this->payload(['products' => [['product_batch_id' => $this->toxin->id, 'quantity' => '']]]))->assertSessionHasErrors('products.0.quantity');
        $this->post($url, $this->payload(['products' => [['product_batch_id' => $this->toxin->id, 'quantity' => '0']]]))->assertSessionHasErrors('products.0.quantity');
        $this->post($url, $this->payload(['products' => [['product_batch_id' => $this->toxin->id, 'quantity' => '1,2345']]]))->assertSessionHasErrors('products.0.quantity');
        $this->post($url, $this->payload(['products' => [
            ['product_batch_id' => $this->toxin->id, 'quantity' => '1'],
            ['product_batch_id' => $this->toxin->id, 'quantity' => '2'],
        ]]))->assertSessionHasErrors('products.0.product_batch_id');
        $this->post($url, $this->payload(['products' => array_fill(0, 11, ['product_batch_id' => '', 'quantity' => ''])]))->assertSessionHasErrors('products');

        $this->assertSame(0, Treatment::count());
    }

    public function test_treatment_date_cannot_be_in_the_future_and_return_cannot_precede_it(): void
    {
        $patient = Patient::factory()->create();
        $url = "/pacientes/{$patient->id}/atendimentos";

        $this->actingAs(User::factory()->professional()->create());

        $this->post($url, $this->payload(['performed_at' => now()->addDays(2)->format('Y-m-d\TH:i'), 'return_date' => null]))
            ->assertSessionHasErrors('performed_at');

        $this->post($url, $this->payload(['return_date' => '2026-09-30']))->assertSessionHasErrors('return_date');

        // Retorno no mesmo dia é aceito.
        $this->post($url, $this->payload(['return_date' => '2026-10-01']))->assertSessionHasNoErrors();
    }

    public function test_quantities_accept_brazilian_decimals(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->professional()->create())
            ->post("/pacientes/{$patient->id}/atendimentos", $this->payload(['products' => [['product_batch_id' => $this->toxin->id, 'quantity' => '0,5']]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('0.500', DB::table('treatment_products')->value('quantity'));
    }

    public function test_typical_products_are_suggested_when_none_is_informed(): void
    {
        $procedure = $this->procedure('Preenchimento labial');
        $filler = Product::factory()->create(['name' => 'Ácido hialurônico']);
        $needle = Product::factory()->create(['name' => 'Agulha 30G']);
        $absent = Product::factory()->create(['name' => 'Sem estoque']);

        $procedure->products()->sync([$filler->id => ['quantity' => 1], $needle->id => ['quantity' => 2.5], $absent->id => ['quantity' => 1]]);

        // O sistema escolhe o lote utilizável que vence primeiro.
        ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'VENCIDO', 'expires_on' => '2026-10-01', 'quantity' => 5]);
        ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'ZERADO', 'expires_on' => '2026-11-01', 'quantity' => 0]);
        ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'DEPOIS', 'expires_on' => '2027-12-01', 'quantity' => 5]);
        $first = ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'PRIMEIRO', 'expires_on' => '2027-01-15', 'quantity' => 5]);
        $only = ProductBatch::factory()->create(['product_id' => $needle->id, 'batch_code' => 'UNICO', 'expires_on' => null, 'quantity' => 50]);

        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->professional()->create());

        $this->post("/pacientes/{$patient->id}/atendimentos", $this->payload(['procedure_id' => $procedure->id, 'products' => []]))
            ->assertSessionHas('success', 'Atendimento salvo. Os produtos padrão do procedimento foram sugeridos: confira antes de finalizar.');

        $this->assertEqualsCanonicalizing(
            [[$first->id, '1.000'], [$only->id, '2.500']],
            Treatment::firstOrFail()->products->map(fn ($used) => [$used->product_batch_id, $used->quantity])->all(),
        );

        // Com produtos informados, nada é sugerido por cima.
        $this->post("/pacientes/{$patient->id}/atendimentos", $this->payload(['procedure_id' => $procedure->id]));

        $this->assertSame([$this->toxin->id], Treatment::latest('id')->firstOrFail()->products->pluck('product_batch_id')->all());
    }

    public function test_only_the_responsible_professional_or_an_admin_can_edit_a_draft(): void
    {
        $author = User::factory()->professional()->create();
        $treatment = Treatment::factory()->create(['professional_id' => $author->id, 'created_by' => $author->id]);
        $url = "/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}";

        // Outro profissional lê, mas não altera nem finaliza.
        $this->actingAs(User::factory()->professional()->create());
        $this->get($url)->assertOk()->assertDontSee('Editar')->assertDontSee('Finalizar atendimento')->assertDontSee('Descartar');
        $this->get("{$url}/editar")->assertForbidden();
        $this->put($url, $this->payload(['treated_areas' => 'Alterado por terceiro']))->assertForbidden();
        $this->get("{$url}/finalizar")->assertForbidden();

        $this->actingAs($author);
        $this->get("{$url}/editar")->assertOk();
        $this->put($url, $this->payload(['procedure_id' => $this->procedure('Preenchimento labial')->id]))->assertSessionHasNoErrors();
        $this->assertSame('Preenchimento labial', $treatment->refresh()->procedure->name);

        $this->actingAs(User::factory()->admin()->create());
        $this->put($url, $this->payload(['treated_areas' => 'Corrigido pelo admin']))->assertSessionHasNoErrors();

        $treatment->refresh();

        $this->assertSame('Corrigido pelo admin', $treatment->treated_areas);
        // Admin editando sem informar o profissional não rouba a autoria.
        $this->assertSame($author->id, $treatment->professional_id);
    }

    public function test_update_audit_records_field_names_but_never_values(): void
    {
        $user = User::factory()->professional()->create();
        $patient = Patient::factory()->create();
        $url = "/pacientes/{$patient->id}/atendimentos";
        $other = $this->batch('Toxina botulínica 100 U', 'OUTRO-LOTE', 100, '2027-08-31');

        $this->actingAs($user);
        $this->post($url, $this->payload());
        $treatment = Treatment::firstOrFail();

        // Sem mudanças: nada é registrado.
        $this->put("{$url}/{$treatment->id}", $this->payload())->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::TreatmentUpdated->value]);

        $this->put("{$url}/{$treatment->id}", $this->payload([
            'complications' => 'Edema sigiloso',
            'products' => [['product_batch_id' => $other->id, 'quantity' => '30']],
        ]))->assertSessionHasNoErrors();

        $log = AuditLog::where('action', AuditAction::TreatmentUpdated->value)->sole();

        $this->assertEqualsCanonicalizing(['complications', 'products'], $log->metadata['fields']);
        $this->assertStringNotContainsString('sigiloso', (string) json_encode($log->metadata));
        $this->assertSame([$other->id], $treatment->products()->pluck('product_batch_id')->all());
    }

    public function test_a_draft_can_be_discarded_by_its_owner_and_frees_the_appointment(): void
    {
        $author = User::factory()->professional()->create();
        $treatment = Treatment::factory()->create(['professional_id' => $author->id, 'created_by' => $author->id]);
        $appointment = Appointment::factory()->at('2026-10-08 12:00')->create(['patient_id' => $treatment->patient_id, 'treatment_id' => $treatment->id]);
        $url = "/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}";

        $this->actingAs(User::factory()->professional()->create())->delete($url)->assertForbidden();
        $this->assertNotSoftDeleted($treatment);

        $this->actingAs($author)->delete($url)->assertRedirect(route('patients.show', $treatment->patient_id));

        // Descartar não apaga: o registro continua guardado.
        $this->assertSoftDeleted($treatment);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TreatmentDiscarded->value, 'auditable_id' => $treatment->id]);

        // O agendamento volta a poder iniciar um atendimento.
        $appointment->refresh();
        $this->assertNull($appointment->treatment_id);
        $this->assertTrue($appointment->isOpen());
    }

    public function test_a_treatment_is_only_reachable_through_its_own_patient(): void
    {
        $treatment = Treatment::factory()->create();
        $otherPatient = Patient::factory()->create();

        $this->actingAs(User::factory()->admin()->create());

        $this->get("/pacientes/{$otherPatient->id}/atendimentos/{$treatment->id}")->assertNotFound();
        $this->put("/pacientes/{$otherPatient->id}/atendimentos/{$treatment->id}", $this->payload())->assertNotFound();
        $this->post("/pacientes/{$otherPatient->id}/atendimentos/{$treatment->id}/finalizar", ['amount' => '0'])->assertNotFound();
        $this->get("/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}")->assertOk();

        $this->assertTrue($treatment->refresh()->isDraft());
    }

    public function test_treatments_of_an_archived_patient_are_unreachable(): void
    {
        $treatment = Treatment::factory()->create();
        $treatment->patient->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}")
            ->assertNotFound();
    }

    public function test_viewing_a_treatment_is_audited(): void
    {
        $user = User::factory()->professional()->create();
        $treatment = Treatment::factory()->create();

        $this->actingAs($user)->get("/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::TreatmentViewed->value, 'user_id' => $user->id, 'auditable_id' => $treatment->id]);
    }

    public function test_health_alerts_are_shown_on_the_treatment_screens(): void
    {
        $patient = Patient::factory()->create();
        $patient->anamnesis()->create(['allergies' => 'Lidocaína', 'pregnant_or_lactating' => true]);

        $this->actingAs(User::factory()->professional()->create())
            ->get("/pacientes/{$patient->id}/atendimentos/novo")
            ->assertOk()
            ->assertSee('Lidocaína')
            ->assertSee('Gestante ou lactante.')
            ->assertSee('Atualizar a ficha de saúde (anamnese)');
    }

    public function test_output_is_escaped(): void
    {
        $treatment = Treatment::factory()->create([
            'procedure_id' => $this->procedure('<script>alert(1)</script>')->id,
            'description' => '<img src=x onerror=alert(2)>',
            'assessment' => '<svg onload=alert(3)>',
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get("/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<svg onload=alert(3)>', false);
        $this->get("/pacientes/{$treatment->patient_id}")->assertDontSee('<script>alert(1)</script>', false);
    }
}
