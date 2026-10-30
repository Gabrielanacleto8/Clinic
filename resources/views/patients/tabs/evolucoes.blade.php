@php use App\Support\ClinicTime; @endphp
<section class="card mt-4 p-6" aria-labelledby="evolucoes-do-paciente">
    <h2 id="evolucoes-do-paciente" class="sr-only">Evoluções</h2>
    @if ($records->isEmpty())
        <p class="py-6 text-center text-sm text-slate-500">
            Nenhuma evolução registrada. Elas são acrescentadas na página do atendimento.
        </p>
    @else
        <ol class="space-y-4">
            @foreach ($records as $note)
                <li class="rounded-lg border border-slate-200 px-4 py-3">
                    <p class="text-xs text-slate-500">
                        {{ ClinicTime::format($note->created_at) }} · {{ $note->author?->name ?? 'Usuário removido' }} ·
                        <a href="{{ route('patients.treatments.show', [$patient, $note->treatment]) }}" class="text-slate-800 hover:underline">
                            {{ $note->treatment->procedure->name }} de {{ ClinicTime::format($note->treatment->performed_at, 'd/m/Y') }}
                        </a>
                    </p>
                    <p class="mt-1 text-sm whitespace-pre-line">{{ $note->body }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</section>

<div class="mt-4">{{ $records->links() }}</div>
