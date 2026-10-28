<?php

namespace App\Support;

/**
 * Linha de planilha CSV no formato que o Excel brasileiro abre direto:
 * ponto e vírgula entre colunas e aspas em todo campo.
 */
final class CsvRow
{
    /**
     * @param  list<string|int|float|null>  $cells
     */
    public static function make(array $cells): string
    {
        return implode(';', array_map(self::cell(...), $cells))."\r\n";
    }

    /**
     * Texto que começa com = + - @ (ou tabulação e retorno) seria lido pelo
     * Excel como fórmula: uma descrição digitada poderia executar comandos
     * no computador de quem abre a planilha. O apóstrofo neutraliza.
     */
    private static function cell(string|int|float|null $value): string
    {
        $text = (string) $value;

        if ($text !== '' && ! is_int($value) && ! is_float($value) && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }
}
