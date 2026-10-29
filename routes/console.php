<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tarefas periódicas. Em produção rodam no serviço "scheduler" do
| docker-compose.production.yml (php artisan schedule:work). O backup do banco
| e dos arquivos fica no serviço "backup", que tem o mysqldump da mesma versão
| do MySQL (ver docs/deploy.md).
*/

Schedule::command('clinic:prune')
    ->dailyAt('04:00')
    ->timezone((string) config('clinic.timezone'))
    ->withoutOverlapping()
    ->onOneServer();

// Despesas fixas do mês (aluguel, salários): lançadas em aberto no primeiro dia.
Schedule::command('clinic:recurring-expenses')
    ->dailyAt('05:00')
    ->timezone((string) config('clinic.timezone'))
    ->withoutOverlapping()
    ->onOneServer();
