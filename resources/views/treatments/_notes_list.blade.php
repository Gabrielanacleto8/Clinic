@php use App\Support\ClinicTime; @endphp
{{-- Evoluções e adendos, do mais antigo ao mais recente. Espera notes.author carregado. --}}
@if ($treatment->notes->isEmpty())
    <p class="mt-4 text-sm text-slate-600">Nenhuma evolução registrada.</p>
@else
    <ol class="mt-4 space-y-4">
        @foreach ($treatment->notes as $note)
            <li class="rounded-lg border border-slate-200 px-4 py-3">
                <p class="text-xs text-slate-500">{{ ClinicTime::format($note->created_at) }} · {{ $note->author?->name ?? 'Usuário removido' }}</p>
                <p class="mt-1 text-sm whitespace-pre-line">{{ $note->body }}</p>
            </li>
        @endforeach
    </ol>
@endif
