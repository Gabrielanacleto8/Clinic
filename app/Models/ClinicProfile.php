<?php

namespace App\Models;

use App\Support\Cnpj;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Dados da clínica impressos nos documentos. Há uma linha só (id 1).
 */
#[Fillable(['legal_name', 'cnpj', 'address', 'phone', 'email', 'technical_lead'])]
class ClinicProfile extends Model
{
    public const SINGLETON_ID = 1;

    /**
     * A linha da clínica, ou uma em branco enquanto ninguém a preencheu.
     */
    public static function current(): self
    {
        return self::query()->find(self::SINGLETON_ID) ?? new self;
    }

    /**
     * Nome no topo dos documentos: a razão social, ou o nome do sistema.
     */
    public function displayName(): string
    {
        return $this->legal_name ?: (string) config('app.name');
    }

    /**
     * Linhas de identificação abaixo do nome, só com o que foi preenchido.
     *
     * @return list<string>
     */
    public function documentLines(): array
    {
        return array_values(array_filter([
            $this->cnpj ? 'CNPJ '.Cnpj::format($this->cnpj) : null,
            $this->address,
            implode(' · ', array_filter([$this->phone ? Phone::format($this->phone) : null, $this->email])) ?: null,
            $this->technical_lead ? 'Responsável técnico: '.$this->technical_lead : null,
        ]));
    }
}
