<?php

namespace App\Enums;

/**
 * O que uma coluna do quadro significa para a tarefa: em aberto conta como
 * pendente, concluída entra na produtividade e cancelada fica fora das duas.
 */
enum TaskColumnKind: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Done => 'Concluída',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(array_column(self::cases(), 'value'), array_map(fn (self $kind) => $kind->label(), self::cases()));
    }
}
