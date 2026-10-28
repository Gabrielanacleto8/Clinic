@php use App\Support\ClinicTime; @endphp
{{-- Versões anteriores da ficha de saúde: datas e autores; o conteúdo abre em página própria, auditada. --}}
<section class="card p-6" aria-labelledby="versoes">
    <h2 id="versoes" class="text-sm font-semibold tracking-wide text-slate-500 uppercase">Versões anteriores</h2>
    @if ($versions->isEmpty())
        <p class="mt-2 text-sm text-slate-600">Nenhuma. A cada alteração, o que a ficha dizia antes fica guardado aqui.</p>
    @else
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
            @foreach ($versions as $item)
                <li>
                    <a href="{{ route('patients.anamnesis.version', [$patient, $item]) }}" @if (($current ?? null) === $item->id) aria-current="page" @endif
                       class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 rounded px-2 py-2 hover:bg-slate-50 aria-[current=page]:bg-teal-50">
                        <span class="font-medium text-teal-800">Válida até {{ ClinicTime::format($item->created_at, 'd/m/Y H:i') }}</span>
                        <span class="text-slate-500">
                            {{ $item->recorded_at ? 'gravada em '.ClinicTime::format($item->recorded_at, 'd/m/Y') : 'gravada antes do histórico' }}
                            por {{ $item->author?->name ?? 'usuário removido' }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
