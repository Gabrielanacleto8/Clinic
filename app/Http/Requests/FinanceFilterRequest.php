<?php

namespace App\Http\Requests;

use App\Models\FinancialEntry;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros do financeiro, os mesmos na tela e na planilha exportada.
 */
class FinanceFilterRequest extends FormRequest
{
    public const TYPES = ['todos' => 'Receitas e despesas', 'receitas' => 'Só receitas', 'despesas' => 'Só despesas'];

    public const STATUSES = [
        'todas' => 'Todas as situações',
        'pagas' => 'Pagas',
        'abertas' => 'Em aberto (a vencer e vencidas)',
        'pendentes' => 'A vencer',
        'vencidas' => 'Vencidas no período',
        'vencidas_todas' => 'Vencidas (todos os meses)',
    ];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', FinancialEntry::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // "mes" é o atalho dos links do Início e do faturamento por período.
            'mes' => ['nullable', 'date_format:Y-m', 'after:2000-01', 'before:2100-01'],
            'de' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01', 'after_or_equal:de'],
            'tipo' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'situacao' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'paciente' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mes' => 'mês', 'de' => 'data inicial', 'ate' => 'data final', 'tipo' => 'tipo', 'situacao' => 'situação', 'paciente' => 'paciente'];
    }

    /**
     * Início e fim do período (Y-m-d): "de"/"até", ou o mês informado, ou o mês corrente.
     *
     * @return array{string, string}
     */
    public function period(): array
    {
        $from = $this->validated('de');
        $until = $this->validated('ate');

        if ($from !== null || $until !== null) {
            $start = $from ?? CarbonImmutable::createFromFormat('!Y-m-d', $until)->startOfMonth()->format('Y-m-d');
            $end = $until ?? CarbonImmutable::createFromFormat('!Y-m-d', $from)->endOfMonth()->format('Y-m-d');

            return [$start, $end];
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $this->validated('mes') ?? substr(ClinicTime::today(), 0, 7));

        return [$month->format('Y-m-d'), $month->endOfMonth()->format('Y-m-d')];
    }

    public function type(): string
    {
        return $this->validated('tipo') ?? 'todos';
    }

    public function status(): string
    {
        return $this->validated('situacao') ?? 'todas';
    }

    public function patientTerm(): string
    {
        return trim((string) $this->validated('paciente'));
    }
}
