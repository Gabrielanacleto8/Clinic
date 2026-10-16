<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /**
     * Situações em que o paciente ainda é esperado: o horário segue reservado
     * e o atendimento ainda pode ser registrado.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Scheduled, self::Confirmed];
    }

    /**
     * Mudanças que um usuário pode fazer a partir desta situação. "Atendido"
     * nunca aparece: só nasce do registro do atendimento.
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Confirmed, self::NoShow, self::Cancelled],
            self::Confirmed => [self::Scheduled, self::NoShow, self::Cancelled],
            self::NoShow, self::Cancelled => [self::Scheduled],
            self::Completed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendado',
            self::Confirmed => 'Confirmado',
            self::Completed => 'Atendido',
            self::NoShow => 'Faltou',
            self::Cancelled => 'Cancelado',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Scheduled => 'bg-sky-100 text-sky-800',
            self::Confirmed => 'bg-teal-100 text-teal-800',
            self::Completed => 'bg-emerald-100 text-emerald-800',
            self::NoShow => 'bg-amber-100 text-amber-800',
            self::Cancelled => 'bg-slate-200 text-slate-700',
        };
    }

    /**
     * Cor do marcador usado nas visões resumidas da agenda (semana e mês).
     */
    public function dotClasses(): string
    {
        return match ($this) {
            self::Scheduled => 'bg-sky-500',
            self::Confirmed => 'bg-teal-500',
            self::Completed => 'bg-emerald-500',
            self::NoShow => 'bg-amber-500',
            self::Cancelled => 'bg-slate-400',
        };
    }
}
