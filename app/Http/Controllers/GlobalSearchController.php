<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Product;
use App\Support\Like;
use App\Support\Navigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pesquisa do topo: pacientes, procedimentos, produtos e atalhos para os
 * módulos, cada grupo só para quem pode ver aquele módulo. Como na lista de
 * pacientes, o paciente é achado pelo nome, pelo CPF completo ou pelo telefone.
 */
class GlobalSearchController extends Controller
{
    private const LIMIT = 8;

    public function __invoke(Request $request): View
    {
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
        $user = $request->user();
        $ready = mb_strlen($term) >= 2;

        return view('search.index', [
            'term' => $term,
            'ready' => $ready,
            'modules' => $ready ? Navigation::matching($user, $term) : [],
            'patients' => $ready && $user->can('viewAny', Patient::class)
                ? Patient::query()->search($term)->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'birth_date', 'phone'])
                : null,
            'procedures' => $ready && $user->can('viewAny', Procedure::class)
                ? Procedure::query()->where('name', 'like', Like::contains($term))->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'is_active'])
                : null,
            'products' => $ready && $user->can('viewAny', Product::class)
                ? Product::query()->search($term)->orderBy('name')->limit(self::LIMIT)->get(['id', 'name', 'is_active'])
                : null,
        ]);
    }
}
