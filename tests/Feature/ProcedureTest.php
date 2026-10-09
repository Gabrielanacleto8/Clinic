<?php

namespace Tests\Feature;

use App\Enums\ProcedureCategory;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcedureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Toxina botulínica',
            'description' => 'Aplicação em terço superior.',
            'category' => ProcedureCategory::Injectable->value,
            'price' => '1.250,50',
            'duration_minutes' => 45,
            'is_active' => '1',
            ...$overrides,
        ];
    }

    public function test_access_follows_the_role_permissions(): void
    {
        $procedure = Procedure::factory()->create();

        $this->get('/procedimentos')->assertRedirect('/login');

        // Recepção: por padrão, sem acesso ao catálogo.
        $this->actingAs(User::factory()->create());
        $this->get('/procedimentos')->assertForbidden();
        $this->get('/')->assertDontSee(route('procedures.index'), false);

        // Profissional: consulta, mas não altera.
        $this->actingAs(User::factory()->professional()->create());
        $this->get('/procedimentos')->assertOk()->assertSee($procedure->name)->assertDontSee('Novo procedimento')->assertDontSee('Editar');
        $this->get('/procedimentos/novo')->assertForbidden();
        $this->post('/procedimentos', $this->payload())->assertForbidden();
        $this->get("/procedimentos/{$procedure->id}/editar")->assertForbidden();
        $this->put("/procedimentos/{$procedure->id}", $this->payload())->assertForbidden();

        $this->assertSame(1, Procedure::count());
    }

    public function test_admin_can_create_and_update_a_procedure(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/procedimentos/novo')->assertOk();

        $this->post('/procedimentos', $this->payload())->assertRedirect(route('procedures.index'))->assertSessionHasNoErrors();

        $procedure = Procedure::sole();

        $this->assertSame('Toxina botulínica', $procedure->name);
        $this->assertSame(ProcedureCategory::Injectable, $procedure->category);
        // Valor digitado no padrão brasileiro.
        $this->assertSame('1250.50', $procedure->price);
        $this->assertSame(45, $procedure->duration_minutes);
        $this->assertTrue($procedure->is_active);

        $this->get('/procedimentos')->assertSee('Toxina botulínica')->assertSee('Injetáveis')->assertSee('45 min')->assertSee('R$ 1.250,50');
        $this->get("/procedimentos/{$procedure->id}/editar")->assertOk()->assertSee('value="1250,50"', false);

        $this->put("/procedimentos/{$procedure->id}", $this->payload(['name' => 'Toxina botulínica full face', 'price' => '1800', 'is_active' => '0']))
            ->assertSessionHasNoErrors();

        $procedure->refresh();

        $this->assertSame('Toxina botulínica full face', $procedure->name);
        $this->assertSame('1800.00', $procedure->price);
        $this->assertFalse($procedure->is_active);
    }

    public function test_procedure_data_is_validated(): void
    {
        Procedure::factory()->create(['name' => 'Já Existe']);

        $this->actingAs(User::factory()->admin()->create());

        $invalid = [
            'name' => ['', 'a', 'Já Existe', str_repeat('a', 151)],
            'category' => ['', 'inexistente'],
            'price' => ['', 'abc', '-10', '10,999', '1000000', '1,2,3'],
            'duration_minutes' => ['', 0, 40, 600, 'x'],
            'description' => [str_repeat('a', 2001)],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $this->post('/procedimentos', $this->payload([$field => $value]))->assertSessionHasErrors($field);
            }
        }

        $this->assertSame(1, Procedure::count());
    }

    public function test_money_is_accepted_in_common_brazilian_formats(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['850' => '850.00', '850,5' => '850.50', '1.250,00' => '1250.00', 'R$ 99,90' => '99.90', '0' => '0.00', '120.5' => '120.50'] as $typed => $stored) {
            $this->post('/procedimentos', $this->payload(['name' => "Procedimento {$typed}", 'price' => (string) $typed]))->assertSessionHasNoErrors();

            $this->assertSame($stored, Procedure::where('name', "Procedimento {$typed}")->sole()->price);
        }
    }

    public function test_a_procedure_can_keep_its_own_name_when_edited(): void
    {
        $procedure = Procedure::factory()->create(['name' => 'Microagulhamento']);

        $this->actingAs(User::factory()->admin()->create())
            ->put("/procedimentos/{$procedure->id}", $this->payload(['name' => 'Microagulhamento']))
            ->assertSessionHasNoErrors();
    }

    public function test_listing_can_be_searched_and_filtered(): void
    {
        Procedure::factory()->create(['name' => 'Toxina botulínica', 'category' => ProcedureCategory::Injectable]);
        Procedure::factory()->create(['name' => 'Limpeza de pele', 'category' => ProcedureCategory::Facial]);
        Procedure::factory()->inactive()->create(['name' => 'Peeling antigo', 'category' => ProcedureCategory::Facial]);

        $this->actingAs(User::factory()->admin()->create());

        // Por padrão só os ativos.
        $this->get('/procedimentos')->assertSee('Toxina botulínica')->assertSee('Limpeza de pele')->assertDontSee('Peeling antigo');
        $this->get('/procedimentos?situacao=inativos')->assertSee('Peeling antigo')->assertDontSee('Limpeza de pele');
        $this->get('/procedimentos?situacao=todos')->assertSee('Peeling antigo')->assertSee('Limpeza de pele');
        $this->get('/procedimentos?q=toxina')->assertSee('Toxina botulínica')->assertDontSee('Limpeza de pele');
        $this->get('/procedimentos?categoria=facial')->assertSee('Limpeza de pele')->assertDontSee('Toxina botulínica');
        $this->get('/procedimentos?q=%25')->assertSee('Nenhum procedimento encontrado.');

        $this->get('/procedimentos?categoria=x')->assertSessionHasErrors('categoria');
        $this->get('/procedimentos?situacao=x')->assertSessionHasErrors('situacao');
    }

    public function test_listing_is_paginated_and_keeps_the_filters(): void
    {
        Procedure::factory()->count(20)->create();

        $response = $this->actingAs(User::factory()->admin()->create())->get('/procedimentos?situacao=todos');

        $response->assertOk()->assertSee('20 registros')->assertSee('situacao=todos&amp;page=2', false);
        $this->assertSame(15, substr_count($response->getContent(), '>Editar</a>'));
    }

    public function test_inactive_procedures_cannot_be_chosen_but_stay_on_existing_records(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $inactive = Procedure::factory()->inactive()->create(['name' => 'Fora de Linha']);
        $professional = User::factory()->professional()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($professional);

        $this->get('/agendamentos/novo')->assertOk()->assertDontSee('Fora de Linha');
        $this->post('/agendamentos', [
            'patient_id' => $patient->id, 'professional_id' => $professional->id, 'scheduled_at' => '2026-10-09T10:00',
            'duration_minutes' => 30, 'procedure_id' => $inactive->id,
        ])->assertSessionHasErrors('procedure_id');

        // Um agendamento antigo com o procedimento desativado continua editável.
        $appointment = Appointment::factory()->at('2026-10-09 15:00')->create(['procedure_id' => $inactive->id, 'professional_id' => $professional->id]);

        $this->get("/agendamentos/{$appointment->id}/editar")->assertOk()->assertSee('Fora de Linha');
        $this->put("/agendamentos/{$appointment->id}", [
            'professional_id' => $professional->id, 'scheduled_at' => '2026-10-09T16:00', 'duration_minutes' => 30, 'procedure_id' => $inactive->id,
        ])->assertSessionHasNoErrors();
    }

    public function test_appointment_form_carries_the_estimated_duration_of_each_procedure(): void
    {
        $procedure = Procedure::factory()->create(['name' => 'Bioestimulador', 'duration_minutes' => 90]);

        $this->actingAs(User::factory()->create())
            ->get('/agendamentos/novo')
            ->assertSee('<option value="'.$procedure->id.'" data-duration="90"', false);
    }

    public function test_typical_products_can_be_configured_with_estimated_quantities(): void
    {
        $toxin = Product::factory()->create(['name' => 'Toxina 100 U']);
        $needle = Product::factory()->create(['name' => 'Agulha 30G']);
        Product::factory()->create(['name' => 'Fora de Linha', 'is_active' => false]);

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/procedimentos/novo')->assertOk()->assertSee('Toxina 100 U')->assertDontSee('Fora de Linha');

        $this->post('/procedimentos', $this->payload(['products' => [
            ['product_id' => $toxin->id, 'quantity' => '50'],
            ['product_id' => '', 'quantity' => ''],
            ['product_id' => $needle->id, 'quantity' => '2,5'],
        ]]))->assertSessionHasNoErrors();

        $procedure = Procedure::with('products')->sole();

        $this->assertEqualsCanonicalizing(
            [[$toxin->id, '50.000'], [$needle->id, '2.500']],
            $procedure->products->map(fn (Product $product) => [$product->id, $product->pivot->quantity])->all(),
        );

        $this->get("/procedimentos/{$procedure->id}/editar")->assertOk()->assertSee('value="2,5"', false);

        // Salvar com outra lista substitui a anterior.
        $this->put("/procedimentos/{$procedure->id}", $this->payload(['products' => [['product_id' => $needle->id, 'quantity' => '4']]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([[$needle->id, '4.000']], $procedure->products()->get()->map(fn (Product $product) => [$product->id, $product->pivot->quantity])->all());
    }

    public function test_typical_products_are_validated(): void
    {
        $product = Product::factory()->create();

        $this->actingAs(User::factory()->admin()->create());

        $this->post('/procedimentos', $this->payload(['products' => [['product_id' => $product->id, 'quantity' => '']]]))
            ->assertSessionHasErrors('products.0.quantity');
        $this->post('/procedimentos', $this->payload(['products' => [['product_id' => $product->id, 'quantity' => '0']]]))
            ->assertSessionHasErrors('products.0.quantity');
        $this->post('/procedimentos', $this->payload(['products' => [['product_id' => 999999, 'quantity' => '1']]]))
            ->assertSessionHasErrors('products.0.product_id');
        $this->post('/procedimentos', $this->payload(['products' => [
            ['product_id' => $product->id, 'quantity' => '1'],
            ['product_id' => $product->id, 'quantity' => '2'],
        ]]))->assertSessionHasErrors('products.0.product_id');
        $this->post('/procedimentos', $this->payload(['products' => array_fill(0, 11, ['product_id' => '', 'quantity' => ''])]))
            ->assertSessionHasErrors('products');

        $this->assertSame(0, Procedure::count());
    }

    public function test_each_listed_procedure_opens_a_dialog_with_its_details_and_the_edit_option(): void
    {
        $procedure = Procedure::factory()->create([
            'name' => 'Preenchimento labial',
            'description' => "Ácido hialurônico.\nRetorno em 15 dias.",
            'category' => ProcedureCategory::Injectable,
            'price' => '1800.00',
            'duration_minutes' => 60,
        ]);
        $procedure->products()->sync([Product::factory()->create(['name' => 'Preenchedor 1 mL'])->id => ['quantity' => '1.500']]);
        $bare = Procedure::factory()->create(['name' => 'Avaliação', 'description' => null]);

        $response = $this->actingAs(User::factory()->admin()->create())->get('/procedimentos')->assertOk();

        // A linha e o nome abrem o diálogo; a tabela não tem mais o link de editar.
        $response->assertSee('<tr class="cursor-pointer hover:bg-slate-50" data-dialog-open="procedure-'.$procedure->id.'">', false);
        $this->assertDoesNotMatchRegularExpression('/<table.*Editar.*<\/table>/s', $response->getContent());

        $dialog = $this->procedureDialog($response->getContent(), $procedure);

        foreach (['Preenchimento labial', 'Retorno em 15 dias.', ProcedureCategory::Injectable->label(), 'R$ 1.800,00', 'Ativo', 'Preenchedor 1 mL', '1,5 '] as $text) {
            $this->assertStringContainsString($text, $dialog);
        }

        $this->assertStringContainsString('href="'.route('procedures.edit', $procedure).'"', $dialog);
        $this->assertStringContainsString('Nenhum produto definido.', $this->procedureDialog($response->getContent(), $bare));

        // Quem só consulta vê os mesmos detalhes, sem a opção de editar.
        $readOnly = $this->procedureDialog($this->actingAs(User::factory()->professional()->create())->get('/procedimentos')->getContent(), $procedure);

        $this->assertStringContainsString('Preenchedor 1 mL', $readOnly);
        $this->assertStringNotContainsString('Editar', $readOnly);
    }

    public function test_listing_runs_a_fixed_number_of_queries(): void
    {
        $products = Product::factory()->count(3)->create();

        Procedure::factory()->count(15)->create()
            ->each(fn (Procedure $procedure) => $procedure->products()->sync($products->mapWithKeys(fn (Product $product) => [$product->id => ['quantity' => 1]])->all()));

        $this->actingAs(User::factory()->admin()->create());
        DB::enableQueryLog();

        $this->get('/procedimentos')->assertOk();

        // Contagem, página e produtos típicos, mais sessão e permissões.
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }

    private function procedureDialog(string $html, Procedure $procedure): string
    {
        $this->assertSame(1, preg_match('/<dialog id="procedure-'.$procedure->id.'".*?<\/dialog>/s', $html, $matches), 'Diálogo do procedimento não encontrado.');

        return $matches[0];
    }

    public function test_output_is_escaped(): void
    {
        Procedure::factory()->create(['name' => '<script>alert(1)</script>', 'description' => '<img src=x onerror=alert(2)>']);

        $this->actingAs(User::factory()->admin()->create())->get('/procedimentos')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }
}
