{{-- Conteúdo da janela de orçamento da lista, buscado por app.js ao clicar na linha. Sem layout: o orçamento inteiro, com as ações. --}}
<div data-summary-content>
    @include('plans._details', ['inList' => true])
</div>
