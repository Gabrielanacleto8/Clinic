<?php

namespace App\Enums;

use App\Models\Appointment;
use App\Support\ClinicTime;

/**
 * Módulo de onde o registro de atendimento foi iniciado. Depois de registrar,
 * o usuário volta para ele. Só destinos fixos: o formulário nunca escolhe uma URL.
 */
enum TreatmentOrigin: string
{
    case Home = 'inicio';
    case Agenda = 'agenda';
    case Treatments = 'atendimentos';

    /**
     * Item do menu lateral que fica marcado enquanto o registro está aberto.
     */
    public function menuRoute(): string
    {
        return match ($this) {
            self::Home => 'home',
            self::Agenda => 'agenda.index',
            self::Treatments => 'treatments.index',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Início',
            self::Agenda => 'Agenda',
            self::Treatments => 'Atendimentos',
        };
    }

    /**
     * Para onde voltar. Na agenda, o dia do agendamento atendido.
     */
    public function url(?Appointment $appointment = null): string
    {
        return $this === self::Agenda && $appointment !== null
            ? route('agenda.index', ['data' => ClinicTime::format($appointment->scheduled_at, 'Y-m-d')])
            : route($this->menuRoute());
    }
}
