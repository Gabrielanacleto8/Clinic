<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('clinic:prune')]
#[Description('Apaga do banco o cache e as sessões que já expiraram')]
class PruneExpiredRows extends Command
{
    public function handle(): int
    {
        $now = now()->getTimestamp();
        $deleted = 0;

        // O cache em banco (tentativas de login, por exemplo) só remove a
        // linha vencida quando ela é lida de novo; as demais ficam para sempre.
        if (config('cache.default') === 'database') {
            $deleted += DB::table(config('cache.stores.database.table') ?: 'cache')->where('expiration', '<=', $now)->delete();
            $deleted += DB::table(config('cache.stores.database.lock_table') ?: 'cache_locks')->where('expiration', '<=', $now)->delete();
        }

        // A limpeza de sessões do Laravel é por sorteio; aqui ela é garantida.
        if (config('session.driver') === 'database') {
            $deleted += DB::table(config('session.table') ?: 'sessions')
                ->where('last_activity', '<=', $now - (int) config('session.lifetime') * 60)
                ->delete();
        }

        $this->components->info("Linhas expiradas removidas: {$deleted}.");

        return self::SUCCESS;
    }
}
