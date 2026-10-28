@php
    use App\Enums\Permission;
    use App\Enums\TreatmentPlanStatus;
    use App\Support\ClinicTime;
    use App\Support\InstallmentPlan;
    use App\Support\LocalizedDecimal;

    $user = auth()->user();
    $total = $plan->total();
    $remaining = $plan->remainingSessions();
    $entry = $plan->relationLoaded('financialEntry') ? $plan->financialEntry : null;
    $paid = $entry?->installments->whereNotNull('paid_at')->sum('amount') ?? 0;
    $heading = 'text-sm font-semibold tracking-wide text-slate-500 uppercase';
@endphp
<x-layouts.app :title="'Orçamento nº '.$plan->id.' · '.$patient->name" section="plans.index">
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('patients.show', [$patient, 'aba' => 'orcamentos']) }}#historico" class="text-sm text-slate-600 hover:underline">← {{ $patient->name }}</a>
                <h1 class="mt-1 flex flex-wrap items-center gap-2 text-xl font-semibold tracking-tight">
                    {{ $plan->isApproved() || $plan->status === TreatmentPlanStatus::Closed ? 'Pacote' : 'Orçamento' }} nº {{ $plan->id }}
                    <span class="badge {{ $plan->status->badgeClasses() }}">{{ $plan->status->label() }}</span>
                    @if ($plan->isExpired())
                        <span class="badge bg-red-100 text-red-800">Validade vencida</span>
                    @endif
                </h1>
                <p class="mt-1 text-sm text-slate-600">
                    Criado em {{ ClinicTime::format($plan->created_at, 'd/m/Y') }} por {{ $plan->creator?->name ?? 'usuário removido' }}
                    @if ($plan->approved_at) · aprovado em {{ ClinicTime::format($plan->approved_at, 'd/m/Y') }} por {{ $plan->approver?->name ?? 'usuário removido' }} @endif
                    @if ($plan->closed_at) · {{ $plan->status === TreatmentPlanStatus::Declined ? 'recusado' : 'encerrado' }} em {{ ClinicTime::format($plan->closed_at, 'd/m/Y') }} por {{ $plan->closer?->name ?? 'usuário removido' }} @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($plan->isApproved() && $remaining > 0)
                    @can('create', App\Models\Appointment::class)
                        <a href="{{ route('appointments.create', ['paciente' => $patient->id, 'procedimento' => $plan->items->first(fn ($item) => $item->remainingSessions() > 0)?->procedure_id]) }}" class="btn btn-primary">Agendar sessão</a>
                    @endcan
                @endif
                @can('approve', $plan)
                    <button type="button" class="btn btn-primary" data-dialog-open="plan-approval">Aprovar</button>
                @endcan
                @can('update', $plan)
                    <a href="{{ route('patients.plans.edit', [$patient, $plan]) }}" class="btn btn-secondary">Editar</a>
                @endcan
                <a href="{{ route('patients.plans.print', [$patient, $plan]) }}" class="btn btn-secondary">Imprimir</a>
            </div>
        </div>

        <section class="card relative overflow-x-auto" aria-labelledby="itens">
            <h2 id="itens" class="{{ $heading }} px-4 pt-4 sm:px-6">Procedimentos</h2>
            <table class="table-stack mt-2 w-full min-w-xl text-left text-sm">
                <thead class="border-y border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium sm:px-6">Procedimento</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Sessões</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Por sessão</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">Subtotal</th>
                        @unless ($plan->isOpen())
                            <th scope="col" class="px-4 py-2 text-right font-medium sm:px-6">Usadas</th>
                        @endunless
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($plan->items as $item)
                        <tr>
                            <td class="px-4 py-2 font-medium sm:px-6">
                                {{ $item->procedure->name }}
                                @if ($item->relationLoaded('treatments') && $item->treatments->isNotEmpty())
                                    <span class="block text-xs font-normal text-slate-500">
                                        @foreach ($item->treatments as $treatment)
                                            <a href="{{ route('patients.treatments.show', [$patient, $treatment]) }}" @class(['hover:underline', 'line-through' => $treatment->voided_at])>{{ ClinicTime::format($treatment->performed_at, 'd/m/Y') }}</a>@if (! $loop->last), @endif
                                        @endforeach
                                    </span>
                                @endif
                            </td>
                            <td data-label="Sessões" class="px-4 py-2 text-right tabular-nums">{{ $item->sessions }}</td>
                            <td data-label="Por sessão" class="px-4 py-2 text-right tabular-nums">{{ LocalizedDecimal::money($item->unit_price) }}</td>
                            <td data-label="Subtotal" class="px-4 py-2 text-right tabular-nums">{{ LocalizedDecimal::money($item->sessions * (float) $item->unit_price) }}</td>
                            @unless ($plan->isOpen())
                                <td data-label="Usadas" class="px-4 py-2 text-right tabular-nums sm:px-6">{{ (int) $item->used_sessions }} de {{ $item->sessions }}</td>
                            @endunless
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-slate-200 bg-slate-50">
                    <tr>
                        <td colspan="3" class="px-4 py-2 font-medium sm:px-6">Total</td>
                        <td class="px-4 py-2 text-right font-semibold tabular-nums">{{ LocalizedDecimal::money($total) }}</td>
                        @unless ($plan->isOpen())
                            <td class="px-4 py-2 text-right text-xs text-slate-600 sm:px-6">{{ $remaining }} {{ $remaining === 1 ? 'sessão restante' : 'sessões restantes' }}</td>
                        @endunless
                    </tr>
                </tfoot>
            </table>
        </section>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <section class="card p-6" aria-labelledby="condicoes">
                <h2 id="condicoes" class="{{ $heading }}">Condições</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-slate-500">Válido até</dt>
                        <dd>{{ $plan->valid_until?->format('d/m/Y') ?? 'Sem prazo' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">Observações</dt>
                        <dd class="whitespace-pre-line">{{ $plan->notes ?: '—' }}</dd>
                    </div>
                </dl>
            </section>

            @if ($entry)
                <section class="card p-6" aria-labelledby="cobranca">
                    <h2 id="cobranca" class="{{ $heading }}">Cobrança</h2>
                    <p class="mt-3 text-sm">
                        {{ $entry->payment_method->label() }} · {{ $entry->installments->count() > 1 ? $entry->installments->count().' parcelas' : 'à vista' }}
                    </p>
                    <p class="mt-1 text-sm">Recebido {{ LocalizedDecimal::money($paid) }} de {{ LocalizedDecimal::money($entry->installments->sum('amount')) }}</p>
                    {{-- Pelo id na ficha, nunca pelo nome na URL (logs e histórico do navegador). --}}
                    <a href="{{ route('patients.show', [$patient, 'aba' => 'financeiro']) }}#historico" class="mt-2 inline-block text-sm text-teal-800 hover:underline">Ver e receber na ficha do paciente</a>
                </section>
            @endif
        </div>

        @if ($plan->isOpen() || $plan->isApproved())
            <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-4">
                @can('decline', $plan)
                    <form method="POST" action="{{ route('patients.plans.decline', [$patient, $plan]) }}" data-confirm="Marcar o orçamento nº {{ $plan->id }} como recusado pelo paciente?">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Paciente recusou</button>
                    </form>
                @endcan
                @can('close', $plan)
                    <form method="POST" action="{{ route('patients.plans.close', [$patient, $plan]) }}"
                          data-confirm="Encerrar o pacote nº {{ $plan->id }}? As {{ $remaining }} sessões restantes não poderão mais ser usadas. O financeiro não muda: devolução, se houver, é lançada à parte.">
                        @csrf
                        <button type="submit" class="btn btn-danger">Encerrar pacote</button>
                    </form>
                @endcan
            </div>
        @endif
    </div>

    @can('approve', $plan)
        <x-dialog id="plan-approval" title="Aprovar orçamento">
            <form method="POST" action="{{ route('patients.plans.approve', [$patient, $plan]) }}" class="space-y-4" novalidate>
                @csrf
                <input type="hidden" name="_dialog" value="plan-approval">
                <p class="text-sm text-slate-700">
                    O orçamento vira pacote e a cobrança de {{ LocalizedDecimal::money($total) }} é lançada no financeiro hoje.
                    Cada atendimento registrado depois usa uma sessão, sem nova cobrança.
                </p>
                @if ((float) $total > 0)
                    <x-form.select scope="plan-approval" name="payment_method" label="Forma de pagamento" :options="$paymentMethods" required placeholder="Selecione" />
                    <x-form.select scope="plan-approval" name="installments" label="Parcelas" required selected="1"
                                   :options="collect(range(1, InstallmentPlan::MAX_INSTALLMENTS))->mapWithKeys(fn ($n) => [$n => $n === 1 ? 'À vista' : $n.'x'])->all()" />
                    <x-form.input scope="plan-approval" name="first_due_date" label="Primeiro vencimento" type="date" required :value="ClinicTime::today()"
                                  hint="As demais parcelas vencem de mês em mês." />
                    @if ($user->hasPermission(Permission::FinanceReceive))
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="first_installment_paid" value="0">
                            <input type="checkbox" name="first_installment_paid" value="1" class="size-4 rounded border-slate-300 accent-brand">
                            Pagamento (ou primeira parcela) já recebido
                        </label>
                    @endif
                @else
                    {{-- Pacote sem valor: nada a cobrar, mas os campos seguem obrigatórios no envio. --}}
                    <input type="hidden" name="payment_method" value="{{ App\Enums\PaymentMethod::Cash->value }}">
                    <input type="hidden" name="installments" value="1">
                    <input type="hidden" name="first_due_date" value="{{ ClinicTime::today() }}">
                    <p class="text-sm text-slate-600">Pacote sem valor: nenhuma cobrança será lançada.</p>
                @endif
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Aprovar e lançar cobrança</button>
                </div>
            </form>
        </x-dialog>
    @endcan
</x-layouts.app>
