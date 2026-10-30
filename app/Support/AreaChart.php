<?php

namespace App\Support;

/**
 * Geometria de um gráfico de área desenhado em SVG no servidor: caminhos das
 * séries num quadro de 1000 × 300 (esticado à largura do contêiner) e uma
 * escala que começa em zero e termina num valor redondo.
 */
final class AreaChart
{
    public const WIDTH = 1000;

    public const HEIGHT = 300;

    private const STEPS = 4;

    private readonly float $top;

    /**
     * @param  list<string>  $labels  Rótulo de cada ponto, na ordem.
     * @param  array<string, list<float>>  $series  Nome => um valor por rótulo.
     */
    public function __construct(public readonly array $labels, public readonly array $series)
    {
        $largest = max([0.0, ...array_merge(...array_values($series))]);
        $this->top = $this->niceStep($largest / self::STEPS) * self::STEPS;
    }

    /**
     * Valores das linhas de grade, de cima para baixo.
     *
     * @return list<float>
     */
    public function ticks(): array
    {
        return array_map(fn (int $step) => $this->top / self::STEPS * $step, range(self::STEPS, 0));
    }

    /** Posição horizontal do ponto, em % da largura: o centro da sua coluna. */
    public function xPercent(int $index): float
    {
        return ($index + 0.5) / count($this->labels) * 100;
    }

    /** Posição vertical do valor, em % da altura a partir do topo. */
    public function yPercent(float $value): float
    {
        return $this->y($value) / self::HEIGHT * 100;
    }

    public function line(string $series): string
    {
        $points = array_map(
            fn (float $value, int $index) => $this->format($this->xPercent($index) / 100 * self::WIDTH).','.$this->format($this->y($value)),
            $this->series[$series],
            array_keys($this->series[$series]),
        );

        return 'M'.implode(' L', $points);
    }

    /** A linha fechada até a base, para o preenchimento. */
    public function area(string $series): string
    {
        $first = $this->format($this->xPercent(0) / 100 * self::WIDTH);
        $last = $this->format($this->xPercent(count($this->labels) - 1) / 100 * self::WIDTH);

        return $this->line($series)." L{$last},".self::HEIGHT." L{$first},".self::HEIGHT.' Z';
    }

    /** Valor curto para o eixo: R$ 800, R$ 1,5 mil, R$ 2 mi. */
    public static function compactMoney(float $value): string
    {
        $short = fn (float $number) => number_format($number, fmod($number, 1.0) == 0.0 ? 0 : 1, ',', '.');

        return match (true) {
            $value >= 1_000_000 => 'R$ '.$short(round($value / 1_000_000, 1)).' mi',
            $value >= 1_000 => 'R$ '.$short(round($value / 1_000, 1)).' mil',
            default => 'R$ '.number_format($value, 0, ',', '.'),
        };
    }

    /** Altura do valor no quadro do SVG, a partir do topo. */
    public function y(float $value): float
    {
        return self::HEIGHT - $value / $this->top * self::HEIGHT;
    }

    /** Passo redondo (1, 2, 2,5 ou 5 × potência de 10) que cobre o valor; sem dados, R$ 250. */
    private function niceStep(float $raw): float
    {
        if ($raw <= 0) {
            return 250.0;
        }

        $magnitude = 10 ** floor(log10($raw));
        $fraction = $raw / $magnitude;

        foreach ([1, 2, 2.5, 5, 10] as $nice) {
            if ($fraction <= $nice) {
                return $nice * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    private function format(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
