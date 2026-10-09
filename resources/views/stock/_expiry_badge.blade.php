{{-- Alerta de validade de um lote que ainda tem saldo. --}}
@if ((float) $batch->quantity > 0)
    @if ($batch->isExpired())
        <span class="badge ml-1 bg-red-100 text-red-800">Vencido</span>
    @elseif ($batch->expiresSoon())
        @php $days = $batch->daysToExpire(); @endphp
        <span class="badge ml-1 bg-amber-100 text-amber-800">{{ $days === 0 ? 'Vence hoje' : 'Vence em '.$days.' '.($days === 1 ? 'dia' : 'dias') }}</span>
    @endif
@endif
