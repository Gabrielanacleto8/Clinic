<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\TreatmentPlan;
use App\Models\User;
use App\Services\DiscountLimits;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Desconto sobre o preço de tabela, no atendimento e no orçamento, com limite por perfil.
 */
class FinanceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    private Patient $patient;

    private Procedure $toxin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->professional = User::factory()->professional()->create();
        $this->patient = Patient::factory()->create();
        $this->toxin = $this->procedure('Toxina botulínica', ['price' => 1000]);
    }

    private function record(string $amount, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->professional)->post("/pacientes/{$this->patient->id}/atendimentos", [
            'performed_at' => '2026-10-08T12:00',
            'procedure_id' => $this->toxin->id,
            'description' => 'Aplicação.',
            'amount' => $amount,
            'payment_method' => PaymentMethod::Pix->value,
            'installments' => 1,
            'first_due_date' => '2026-10-08',
            'professional_id' => $this->professional->id,
        ]);
    }

    public function test_treatment_discount_is_limited_by_the_role_and_recorded(): void
    {
        // Profissional: 10% por padrão.
        $this->actingAs($this->professional)->get("/pacientes/{$this->patient->id}/atendimentos/novo")->assertOk()
            ->assertSee('Seu desconto máximo: 10,0% do preço de tabela.')
            ->assertSee('data-discount-input', false);

        $this->record('850,00')->assertSessionHasErrors([
            'amount' => 'Desconto de 15,0% sobre o preço de tabela (R$ 1.000,00). O seu limite é 10,0%: o mínimo é R$ 900,00. Um administrador pode conceder mais.',
        ]);
        $this->assertSame(0, FinancialEntry::query()->count());

        $this->record('900,00')->assertSessionHasNoErrors();
        $this->assertSame('100.00', FinancialEntry::query()->sole()->discount);

        // Acima do preço de tabela: sem desconto. Sem cobrança (cortesia): fora do limite.
        $this->record('1.100,00')->assertSessionHasNoErrors();
        $this->assertSame('0.00', FinancialEntry::query()->latest('id')->first()->discount);
        $this->record('0')->assertSessionHasNoErrors();

        // Administrador não tem limite.
        $this->record('100,00', User::factory()->admin()->create())->assertSessionHasNoErrors();
        $this->assertSame('900.00', FinancialEntry::query()->latest('id')->first()->discount);
    }

    public function test_plan_discount_is_limited_and_goes_to_the_charge(): void
    {
        $peeling = $this->procedure('Peeling químico', ['price' => 380]);
        $this->actingAs($this->professional);

        $store = fn (string $price) => $this->post('/orcamentos', ['patient_id' => $this->patient->id, 'items' => [
            ['procedure_id' => $peeling->id, 'sessions' => '4', 'unit_price' => $price],
        ]]);

        $store('340,00')->assertSessionHasErrors('items');
        $this->assertSame(0, TreatmentPlan::query()->count());

        $store('342,00')->assertSessionHasNoErrors();
        $plan = TreatmentPlan::query()->with('items')->sole();
        $this->assertSame('380.00', $plan->items->first()->list_price);
        $this->assertSame('152.00', $plan->discount());
        $this->get("/pacientes/{$this->patient->id}/orcamentos/{$plan->id}")->assertOk()->assertSee('Tabela R$ 1.520,00 · desconto R$ 152,00');

        $this->post("/pacientes/{$this->patient->id}/orcamentos/{$plan->id}/aprovacao", [
            'payment_method' => PaymentMethod::Pix->value, 'installments' => '1', 'first_due_date' => '2026-10-08',
        ])->assertSessionHasNoErrors();

        $this->assertSame('152.00', FinancialEntry::query()->where('treatment_plan_id', $plan->id)->sole()->discount);

        // Pacote sem valor é cortesia.
        $store('0')->assertSessionHasNoErrors();
    }

    public function test_the_admin_sets_the_limit_of_each_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/usuarios/permissoes')->assertOk()->assertSee('Desconto máximo')->assertSee('name="discount_limits[professional]"', false);

        $this->put('/usuarios/permissoes', ['discount_limits' => ['professional' => '150']])->assertSessionHasErrors('discount_limits.professional');
        $this->put('/usuarios/permissoes', ['discount_limits' => ['admin' => '5']])->assertSessionHasErrors('discount_limits');

        $permissions = collect(Permission::defaultsFor(UserRole::Professional))->map->value->all();
        $receptionist = collect(Permission::defaultsFor(UserRole::Receptionist))->map->value->all();
        $this->put('/usuarios/permissoes', [
            'permissions' => ['professional' => $permissions, 'receptionist' => $receptionist],
            'discount_limits' => ['professional' => '20,5', 'receptionist' => '10'],
        ])->assertSessionHasNoErrors();

        app(DiscountLimits::class)->sync([]);
        $this->assertSame(20.5, app(DiscountLimits::class)->for(UserRole::Professional));
        $log = AuditLog::where('action', AuditAction::DiscountLimitsUpdated->value)->sole();
        $this->assertSame(['professional' => '10 → 20.5'], $log->metadata['limits']);

        $this->record('800,00')->assertSessionHasNoErrors();

        // Só o administrador altera.
        $this->actingAs($this->professional)->put('/usuarios/permissoes', ['discount_limits' => ['professional' => '100']])->assertForbidden();
    }
}
