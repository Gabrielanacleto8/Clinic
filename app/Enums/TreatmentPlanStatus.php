<?php

namespace App\Enums;

/**
 * Orçamento em aberto, aprovado (é o pacote, com sessões a usar), recusado
 * pelo paciente ou encerrado (pacote cujas sessões restantes não serão usadas).
 */
enum TreatmentPlanStatus: string
{
    case Open = 'open';
    case Approved = 'approved';
    case Declined = 'declined';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Orçamento em aberto',
            self::Approved => 'Aprovado',
            self::Declined => 'Recusado',
            self::Closed => 'Encerrado',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Open => 'bg-sky-100 text-sky-800',
            self::Approved => 'bg-emerald-100 text-emerald-800',
            self::Declined => 'bg-slate-200 text-slate-700',
            self::Closed => 'bg-amber-100 text-amber-800',
        };
    }
}
