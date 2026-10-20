<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Datas são gravadas em UTC. Esta classe concentra a conversão de e para o
 * fuso da clínica, usado em tudo o que o usuário vê ou digita.
 */
final class ClinicTime
{
    public const INPUT_FORMAT = 'Y-m-d\TH:i';

    public static function timezone(): string
    {
        return (string) config('clinic.timezone');
    }

    /**
     * Deslocamento atual do fuso da clínica em relação ao UTC ("-03:00"), para
     * conversões feitas no banco: convert_tz com deslocamento numérico não
     * depende das tabelas de fuso do MySQL.
     */
    public static function utcOffset(): string
    {
        return CarbonImmutable::now(self::timezone())->format('P');
    }

    public static function local(DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(self::timezone());
    }

    public static function format(?DateTimeInterface $date, string $format = 'd/m/Y H:i'): string
    {
        return $date ? self::local($date)->format($format) : '';
    }

    /**
     * Data de hoje no fuso da clínica (Y-m-d).
     */
    public static function today(): string
    {
        return self::local(now())->format('Y-m-d');
    }

    /**
     * Início e fim, em UTC, de um dia do calendário da clínica.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function dayBounds(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $date, self::timezone())->startOfDay();

        return [$start->utc(), $start->endOfDay()->utc()];
    }

    /**
     * Valor para <input type="datetime-local">.
     */
    public static function toInput(?DateTimeInterface $date): ?string
    {
        return $date ? self::local($date)->format(self::INPUT_FORMAT) : null;
    }

    /**
     * Interpreta o valor de um <input type="datetime-local"> como horário da clínica.
     */
    public static function fromInput(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::INPUT_FORMAT, $value, self::timezone())->utc();
    }
}
