{{-- Diálogo "Registrar atendimento" do Início e da lista de atendimentos. Espera
     $treatmentForm, de TreatmentFormOptions::forDialog(), e abre por
     [data-dialog-open] com o "scope" que vem nele. --}}
<x-dialog :id="$treatmentForm['scope']" title="Registrar atendimento" size="lg">
    <p class="text-sm text-slate-600">Ao registrar, o estoque é baixado e o financeiro é lançado. Fotos e documentos entram depois, no próprio atendimento.</p>
    <form method="POST" action="{{ route('treatments.store') }}" class="mt-4 space-y-5" novalidate
          data-confirm="{{ App\Services\TreatmentFormOptions::CONFIRMATION }}">
        @include('treatments._form', $treatmentForm)
    </form>
</x-dialog>
