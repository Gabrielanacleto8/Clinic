<?php

namespace App\Console\Commands;

use App\Support\ProductionConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clinic:check')]
#[Description('Confere as chaves, o endereço HTTPS e a pasta das fotos antes de colocar o sistema no ar')]
class CheckProductionConfig extends Command
{
    public function handle(): int
    {
        $problems = ProductionConfig::problems(checkStorage: true);

        if ($problems === []) {
            $this->components->info('Configuração pronta para produção.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return self::FAILURE;
    }
}
