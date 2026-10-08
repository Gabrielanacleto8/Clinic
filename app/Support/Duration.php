<?php

namespace App\Support;

/**
 * Durações oferecidas para procedimentos e agendamentos, em minutos.
 */
final class Duration
{
    public const OPTIONS = [15, 30, 45, 60, 90, 120, 180];

    public static function label(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $rest = $minutes % 60;

        return intdiv($minutes, 60).' h'.($rest > 0 ? " {$rest} min" : '');
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return array_combine(self::OPTIONS, array_map(self::label(...), self::OPTIONS));
    }
}
