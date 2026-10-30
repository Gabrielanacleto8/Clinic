<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Http\RedirectResponse;

/**
 * Para onde voltar depois de uma ação no painel da tarefa: o quadro com os
 * filtros de onde o usuário partiu e a tarefa aberta. A URL anterior só é
 * aproveitada se for o próprio quadro, nunca um endereço vindo de fora.
 */
final class BoardReturn
{
    public static function toTask(?Task $task, ?string $tab = null): RedirectResponse
    {
        $previous = (string) session()->previousUrl();
        $parts = parse_url($previous);
        $board = parse_url(route('tasks.index'));

        $query = [];

        // Caminho do quadro, no próprio domínio (ou sem domínio, que é relativo a ele).
        $sameHost = ! isset($parts['host']) || ($parts['host'] === ($board['host'] ?? null) && ($parts['port'] ?? null) === ($board['port'] ?? null));

        if ($sameHost && ($parts['path'] ?? null) === ($board['path'] ?? null)) {
            parse_str($parts['query'] ?? '', $query);
        }

        unset($query['tarefa'], $query['aba']);

        if ($task !== null) {
            $query['tarefa'] = $task->getKey();
        }

        if ($task !== null && $tab !== null) {
            $query['aba'] = $tab;
        }

        return redirect()->route('tasks.index', $query);
    }
}
