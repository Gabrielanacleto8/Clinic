<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registro de erro de banco sem dados de paciente.
 *
 * A mensagem do MySQL repete valores da consulta ("Duplicate entry 'maria@...'",
 * "Data too long for column 'name'") e o rastro da pilha pode trazer
 * argumentos. Por isso não se loga a exceção: só o SQL com os marcadores "?",
 * os códigos de erro e o ponto do código que fez a consulta.
 */
final class SanitizedQueryReport
{
    public static function log(QueryException $exception): void
    {
        Log::error('Falha de consulta ao banco de dados.', [
            'sqlstate' => $exception->errorInfo[0] ?? (string) $exception->getCode(),
            'driver_code' => $exception->errorInfo[1] ?? null,
            'connection' => $exception->getConnectionName(),
            'sql' => $exception->getSql(),
            'origin' => self::origin($exception),
        ]);
    }

    /**
     * Primeiro arquivo da aplicação (fora de vendor) no rastro da pilha.
     */
    private static function origin(Throwable $exception): ?string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file !== null && str_starts_with($file, $base) && ! str_starts_with($file, $base.'vendor'.DIRECTORY_SEPARATOR)) {
                return substr($file, strlen($base)).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }
}
