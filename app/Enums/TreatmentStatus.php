<?php

namespace App\Enums;

enum TreatmentStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em andamento',
            self::Finalized => 'Finalizado',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-100 text-amber-800',
            self::Finalized => 'bg-emerald-100 text-emerald-800',
        };
    }
}
