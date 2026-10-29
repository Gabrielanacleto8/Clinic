<?php

namespace App\Console\Commands;

use App\Services\RecurringExpenses;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clinic:recurring-expenses')]
#[Description('Lança as despesas fixas do mês que ainda não foram geradas')]
class GenerateRecurringExpenses extends Command
{
    public function handle(RecurringExpenses $expenses): int
    {
        $created = $expenses->generate();

        $this->components->info($created === 0 ? 'Nenhuma despesa recorrente a gerar.' : "{$created} despesa(s) recorrente(s) lançada(s).");

        return self::SUCCESS;
    }
}
