{{-- Correção do custo unitário de uma entrada. Quem abre define a entrada por
     data-dialog-action e o custo atual por data-dialog-values. Só para quem ajusta estoque. --}}
@can('adjust', App\Models\Product::class)
    <x-dialog id="entry-cost" title="Corrigir custo da entrada">
        <form method="POST" action="" class="space-y-4" novalidate data-dialog-reset>
            @csrf
            @method('PATCH')
            <input type="hidden" name="_dialog" value="entry-cost">
            <p class="text-sm font-medium text-slate-700" data-dialog-summary></p>
            <p class="text-sm text-slate-600">
                Use para custo digitado errado na entrada (ex.: 12000 no lugar de 12,00). O saldo não muda. O custo médio do lote, que vale
                para consumos e perdas a partir de agora, passa a considerar o valor corrigido. A correção fica na auditoria com o valor antigo e o novo.
            </p>
            <x-form.input scope="entry-cost" id="entry-cost-value" name="unit_cost" label="Custo unitário (R$)" inputmode="decimal" autocomplete="off" placeholder="0,00"
                          hint="Por unidade de medida do produto. Em branco: entrada sem custo." />

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
                <button type="submit" class="btn btn-primary">Corrigir custo</button>
            </div>
        </form>
    </x-dialog>
@endcan
