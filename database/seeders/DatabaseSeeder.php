<?php

namespace Database\Seeders;

use App\Enums\ProcedureCategory;
use App\Enums\ProductCategory;
use App\Enums\ProductUnit;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Models\User;
use App\Services\StockLedger;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Dados fictícios para desenvolvimento. Cada bloco só roda se a tabela
     * correspondente estiver vazia, então rodar de novo não duplica nada.
     *
     * Nenhum usuário é criado aqui, para nunca existir conta com senha
     * padrão. Use: php artisan clinic:create-admin
     */
    public function run(StockLedger $ledger): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('O seeder de dados fictícios só pode rodar em ambiente local.');
        }

        if (! Patient::query()->exists()) {
            Patient::factory()->count(40)->create();
        }

        $user = User::query()->where('is_active', true)->orderBy('id')->first();

        if (! Procedure::query()->exists()) {
            $this->seedCatalogAndStock($ledger, $user);
        }

        if ($user === null) {
            $this->command?->warn('Nenhum usuário encontrado: a agenda de exemplo não foi criada.');

            return;
        }

        if (! Appointment::query()->exists()) {
            $this->seedAgenda($user);
        }
    }

    private function seedCatalogAndStock(StockLedger $ledger, ?User $user): void
    {
        // nome => [categoria, unidade, mínimo, custo, lotes como [código, meses até vencer, quantidade]]
        $products = [
            'Toxina botulínica 100 U' => [ProductCategory::Injectable, ProductUnit::ToxinUnit, 200, 9.50, [['TB2601', 14, 400], ['TB2512', 1, 100]]],
            'Ácido hialurônico 1 mL' => [ProductCategory::Injectable, ProductUnit::Syringe, 4, 420.00, [['AH7781', 18, 3]]],
            'Bioestimulador de colágeno' => [ProductCategory::Injectable, ProductUnit::Vial, 2, 890.00, [['BC0420', 20, 6]]],
            'Anestésico tópico 30 g' => [ProductCategory::Anesthetic, ProductUnit::Unit, 2, 38.00, [['AT1190', 9, 5]]],
            'Agulha 30G' => [ProductCategory::Disposable, ProductUnit::Unit, 50, 0.45, [['AG3001', null, 300]]],
            'Luva de procedimento' => [ProductCategory::Disposable, ProductUnit::Box, 3, 32.00, [['LV8844', 30, 2]]],
        ];

        $created = [];

        foreach ($products as $name => [$category, $unit, $minimum, $cost, $batches]) {
            $product = Product::create([
                'name' => $name, 'category' => $category, 'unit' => $unit, 'minimum_stock' => $minimum, 'cost' => $cost, 'is_active' => true,
            ]);

            foreach ($batches as [$code, $monthsToExpire, $quantity]) {
                $batch = $product->batches()->create([
                    'batch_code' => $code,
                    'expires_on' => $monthsToExpire === null ? null : now()->addMonths($monthsToExpire)->format('Y-m-d'),
                ]);

                // O saldo nasce de uma entrada, como em produção.
                $ledger->enter($batch, (string) $quantity, 'Estoque inicial de demonstração', $user);
            }

            $created[$name] = $product;
        }

        // nome => [categoria, valor, duração, [produto => quantidade estimada]]
        $procedures = [
            'Avaliação' => [ProcedureCategory::Consultation, 0, 30, []],
            'Retorno' => [ProcedureCategory::Consultation, 0, 15, []],
            'Toxina botulínica' => [ProcedureCategory::Injectable, 1200, 45, ['Toxina botulínica 100 U' => 50, 'Agulha 30G' => 2]],
            'Preenchimento labial' => [ProcedureCategory::Injectable, 1500, 60, ['Ácido hialurônico 1 mL' => 1, 'Anestésico tópico 30 g' => 1, 'Agulha 30G' => 2]],
            'Bioestimulador de colágeno' => [ProcedureCategory::Injectable, 2200, 60, ['Bioestimulador de colágeno' => 1, 'Agulha 30G' => 2]],
            'Microagulhamento' => [ProcedureCategory::Facial, 450, 60, ['Anestésico tópico 30 g' => 1]],
            'Peeling químico' => [ProcedureCategory::Facial, 380, 45, []],
            'Limpeza de pele' => [ProcedureCategory::Facial, 180, 60, []],
        ];

        foreach ($procedures as $name => [$category, $price, $duration, $typical]) {
            $procedure = Procedure::create([
                'name' => $name, 'category' => $category, 'price' => $price, 'duration_minutes' => $duration, 'is_active' => true,
            ]);

            $procedure->products()->sync(collect($typical)->mapWithKeys(fn ($quantity, $product) => [$created[$product]->id => ['quantity' => $quantity]])->all());
        }
    }

    private function seedAgenda(User $user): void
    {
        $procedures = Procedure::query()->pluck('duration_minutes', 'id');
        $patients = Patient::query()->inRandomOrder()->limit(8)->pluck('id');
        $today = CarbonImmutable::createFromFormat('!Y-m-d', ClinicTime::today(), ClinicTime::timezone());

        // Horários de hoje e de amanhã, sem sobreposição para o mesmo profissional.
        $slots = [[0, 9], [0, 10], [0, 14], [0, 16], [1, 9], [1, 11], [1, 15], [2, 10]];

        foreach ($slots as $index => [$dayOffset, $hour]) {
            $procedureId = $procedures->keys()->random();

            $appointment = new Appointment;
            $appointment->schedule($today->addDays($dayOffset)->setTime($hour, 0)->utc(), min(60, (int) $procedures[$procedureId]));
            $appointment->patient_id = $patients[$index];
            $appointment->professional_id = $user->id;
            $appointment->procedure_id = $procedureId;
            $appointment->created_by = $user->id;
            $appointment->save();
        }
    }
}
