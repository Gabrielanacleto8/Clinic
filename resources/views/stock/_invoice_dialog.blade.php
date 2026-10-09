@php
    $scope = 'invoice-import';
    $failed = old('_dialog') === $scope && $errors->has('invoice');
@endphp
{{-- Envio do XML da nota fiscal. A conferência dos itens vem na tela seguinte. --}}
<x-dialog :id="$scope" title="Importar nota fiscal">
    <form method="POST" action="{{ route('stock.invoice.store') }}" enctype="multipart/form-data" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="_dialog" value="{{ $scope }}">

        <p class="text-sm text-slate-600">
            Envie o XML da nota fiscal eletrônica (NF-e) do fornecedor. Os produtos que ainda não existem são cadastrados e as entradas de estoque são lançadas, depois de você conferir cada item.
        </p>

        <div>
            <label for="invoice" class="label">Arquivo XML da nota <span class="text-red-600" aria-hidden="true">*</span></label>
            <input id="invoice" name="invoice" type="file" required accept=".xml,text/xml,application/xml"
                   @if ($failed) aria-invalid="true" aria-describedby="invoice-error" @endif
                   @class(['input file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-medium', 'input-error' => $failed])>
            <p class="mt-1 text-xs text-slate-500">É o arquivo .xml enviado junto com a nota, não o PDF (DANFE). Ele é só lido: não fica guardado no sistema.</p>
            @if ($failed)
                <p id="invoice-error" class="mt-1 text-sm text-red-700">{{ $errors->first('invoice') }}</p>
            @endif
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary">Ler nota fiscal</button>
        </div>
    </form>
</x-dialog>
