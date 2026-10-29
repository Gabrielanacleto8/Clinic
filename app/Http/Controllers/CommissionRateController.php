<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommissionRatesRequest;
use App\Models\FinancialEntry;
use App\Models\Procedure;
use App\Models\User;
use App\Services\CommissionRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Regras de comissão: % padrão por profissional e exceções por procedimento.
 */
class CommissionRateController extends Controller
{
    public function __construct(private readonly CommissionRules $rules) {}

    public function edit(): View
    {
        Gate::authorize('manage', FinancialEntry::class);

        $rules = $this->rules->all();

        return view('finance.commission_rates', [
            // Quem atende hoje e quem já tem regra (mesmo inativo, para poder retirar).
            'professionals' => User::query()
                ->where(fn ($query) => $query->attending()->orWhereIn('id', $rules->keys()))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'rules' => $rules,
            'procedures' => Procedure::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(CommissionRatesRequest $request): RedirectResponse
    {
        $this->rules->sync($request->commissionRules());

        return redirect()->route('finance.commissions.rates')
            ->with('success', 'Regras de comissão salvas. Valem para os atendimentos registrados a partir de agora.');
    }
}
