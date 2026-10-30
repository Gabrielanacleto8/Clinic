<?php

namespace App\Http\Requests;

use App\Models\FinancialEntry;
use App\Services\MonthlyFinance;
use App\Support\ClinicTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros do comparativo mensal: o último mês da janela e quantos meses ela tem.
 */
class FinanceAnalysisRequest extends FormRequest
{
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
            'mes' => ['nullable', 'date_format:Y-m', 'after:2000-01', 'before:2100-01'],
            'meses' => ['nullable', 'integer', Rule::in(MonthlyFinance::WINDOWS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mes' => 'mês', 'meses' => 'quantidade de meses'];
    }

    public function lastMonth(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->validated('mes') ?? substr(ClinicTime::today(), 0, 7));
    }

    public function window(): int
    {
        return (int) ($this->validated('meses') ?? MonthlyFinance::WINDOWS[1]);
    }
}
