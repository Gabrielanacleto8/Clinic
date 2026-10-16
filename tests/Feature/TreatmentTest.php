<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
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
            'amount' => '0',
            ...$overrides,
        ];
    }

    public function test_guests_and_reception_cannot_reach_treatments(): void
    {
        $treatment = Treatment::factory()->create();
        $base = "/pacientes/{$treatment->patient_id}/atendimentos";

        $this->get("{$base}/{$treatment->id}")->assertRedirect('/login');
        $this->post($base, $this->payload())->assertRedirect('/login');

        $this->actingAs(User::factory()->create());

        $this->get("{$base}/novo")->assertForbidden();
        $this->post($base, $this->payload())->assertForbidden();
        $this->get("{$base}/{$treatment->id}")->assertForbidden();
        $this->post("{$base}/{$treatment->id}/evolucoes", ['body' => 'Tentativa'])->assertForbidden();

        $this->assertSame(1, Treatment::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_professional_registers_a_treatment_in_a_single_step(): void
    {
        $user = User::factory()->professional()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($user);
        $this->get("/pacientes/{$patient->id}/atendimentos/novo")
            ->assertOk()
            ->assertSee('Toxina botulínica 100 U')
            ->assertSee('lote C1234')
            ->assertSee('name="amount"', false)
            ->assertSee('Registrar atendimento');

        $this->post("/pacientes/{$patient->id}/atendimentos", $this->payload())->assertSessionHasNoErrors();

        $treatment = Treatment::with('products')->firstOrFail();

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

        // O estoque é baixado no próprio registro. Valor 0 não gera lançamento.
        $this->assertSame('270.000', $this->toxin->refresh()->quantity);
        $this->assertSame($treatment->id, StockMovement::sole()->treatment_id);
        $this->assertSame(0, FinancialEntry::count());

        $log = AuditLog::where('action', AuditAction::TreatmentCreated->value)->sole();
        $this->assertSame([$user->id, $treatment->id], [$log->user_id, $log->auditable_id]);
        $this->assertEquals(['patient_id' => $patient->id, 'products' => 1, 'charged' => false], $log->metadata);

        $this->get("/pacientes/{$patient->id}/atendimentos/{$treatment->id}")
            ->assertOk()
            ->assertSee('Registrado em 08/10/2026')
            ->assertSee('Toxina botulínica')
            ->assertSee('01/10/2026')
            ->assertSee('14:30')
            ->assertSee('C1234')
            ->assertSee('30 un')
            ->assertSee('Evoluções e adendos');

        $this->get("/pacientes/{$patient->id}")->assertSee('Toxina botulínica')->assertSee('Glabela e frontal');
    }

    public function test_a_registered_treatment_cannot_be_edited_or_removed_by_anyone(): void
    {
        $author = User::factory()->professional()->create();
        $treatment = Treatment::factory()->create(['professional_id' => $author->id, 'created_by' => $author->id, 'description' => 'Original']);
        $url = "/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}";

        // Não existe rota de edição nem de exclusão, nem para o autor nem para o administrador.
        foreach ([$author, User::factory()->admin()->create()] as $user) {
            $this->actingAs($user);
            $this->get("{$url}/editar")->assertNotFound();
            $this->put($url, $this->payload(['description' => 'Adulterado']))->assertMethodNotAllowed();
            $this->delete($url)->assertMethodNotAllowed();
            $this->get($url)->assertOk()->assertDontSee('Editar')->assertDontSee('Descartar');
        }

        $treatment->refresh();

        $this->assertSame('Original', $treatment->description);
        $this->assertNotSoftDeleted($treatment);
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
                'professional_id' => $other->id, 'created_by' => $other->id, 'patient_id' => 999,
            ]))
            ->assertSessionHasNoErrors();

        $treatment = Treatment::firstOrFail();

        $this->assertSame($user->id, $treatment->professional_id);
        $this->assertSame($user->id, $treatment->created_by);
        $this->assertSame($patient->id, $treatment->patient_id);
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
            ->assertSessionHasErrors(['products.0.product_batch_id' => 'Este lote não existe, está vencido ou é de um produto inativo.']);
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
        $this->assertSame(0, StockMovement::count());
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

    public function test_the_form_carries_what_each_procedure_fills_in_by_itself(): void
    {
        $procedure = $this->procedure('Preenchimento labial', ['price' => 1850.5]);
        $filler = Product::factory()->create(['name' => 'Ácido hialurônico']);
        $needle = Product::factory()->create(['name' => 'Agulha 30G']);
        $absent = Product::factory()->create(['name' => 'Sem estoque']);

        $procedure->products()->sync([$filler->id => ['quantity' => 1], $needle->id => ['quantity' => 2.5], $absent->id => ['quantity' => 1]]);

        // O sistema sugere o lote utilizável que vence primeiro.
        ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'VENCIDO', 'expires_on' => '2026-10-01', 'quantity' => 5]);
        ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'ZERADO', 'expires_on' => '2026-11-01', 'quantity' => 0]);
        ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'DEPOIS', 'expires_on' => '2027-12-01', 'quantity' => 5]);
        $first = ProductBatch::factory()->create(['product_id' => $filler->id, 'batch_code' => 'PRIMEIRO', 'expires_on' => '2027-01-15', 'quantity' => 5]);
        $only = ProductBatch::factory()->create(['product_id' => $needle->id, 'batch_code' => 'UNICO', 'expires_on' => null, 'quantity' => 50]);
        $plain = $this->procedure('Toxina botulínica', ['price' => 1200]);

        $patient = Patient::factory()->create();

        $response = $this->actingAs(User::factory()->professional()->create())->get("/pacientes/{$patient->id}/atendimentos/novo")
            ->assertOk()
            ->assertSee('data-procedure-defaults=', false)
            ->assertSee('data-treatment-amount', false);

        $defaults = $response->viewData('procedureDefaults');

        $this->assertSame('1850,50', $defaults[$procedure->id]['amount']);
        $this->assertEqualsCanonicalizing(
            [['product_batch_id' => $first->id, 'quantity' => '1'], ['product_batch_id' => $only->id, 'quantity' => '2,5']],
            $defaults[$procedure->id]['products'],
        );
        // Procedimento sem produtos típicos sugere só o valor.
        $this->assertSame(['amount' => '1200,00', 'products' => []], $defaults[$plain->id]);
    }

    public function test_products_are_never_added_behind_the_users_back(): void
    {
        $procedure = $this->procedure('Preenchimento labial');
        $procedure->products()->sync([$this->toxin->product_id => ['quantity' => 5]]);
        $patient = Patient::factory()->create();

        // A sugestão é só do formulário: registrado sem produtos, nada é baixado.
        $this->actingAs(User::factory()->professional()->create())
            ->post("/pacientes/{$patient->id}/atendimentos", $this->payload(['procedure_id' => $procedure->id, 'products' => []]))
            ->assertSessionHasNoErrors();

        $this->assertCount(0, Treatment::firstOrFail()->products);
        $this->assertSame('300.000', $this->toxin->refresh()->quantity);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_a_treatment_is_only_reachable_through_its_own_patient(): void
    {
        $treatment = Treatment::factory()->create();
        $otherPatient = Patient::factory()->create();

        $this->actingAs(User::factory()->admin()->create());

        $this->get("/pacientes/{$otherPatient->id}/atendimentos/{$treatment->id}")->assertNotFound();
        $this->post("/pacientes/{$otherPatient->id}/atendimentos/{$treatment->id}/evolucoes", ['body' => 'Do paciente errado'])->assertNotFound();
        $this->get("/pacientes/{$treatment->patient_id}/atendimentos/{$treatment->id}")->assertOk();

        $this->assertSame(0, $treatment->notes()->count());
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
