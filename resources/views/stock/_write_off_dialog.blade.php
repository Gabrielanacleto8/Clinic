{{-- Baixa de lote vencido como perda (fechado + unidades abertas). Quem abre
     define o lote por data-dialog-action e o motivo por data-dialog-values. --}}
<x-dialog id="batch-write-off" title="Baixar lote vencido como perda">
    <form method="POST" action="" class="space-y-4" novalidate data-dialog-reset>
        @csrf
        <input type="hidden" name="_dialog" value="batch-write-off">
        <p class="text-sm font-medium text-slate-700" data-dialog-summary></p>
        <p class="text-sm text-slate-600">
            Todo o saldo do lote é registrado como perda: o que está fechado e, no produto fracionado, o que resta em cada unidade aberta.
            O valor perdido é calculado pelo custo médio do lote.
        </p>
        <x-form.input scope="batch-write-off" id="write-off-reason" name="reason" label="Motivo" required maxlength="255" autocomplete="off" />

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-danger">Registrar perda</button>
        </div>
    </form>
</x-dialog>
