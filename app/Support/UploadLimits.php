<?php

namespace App\Support;

/**
 * Limites de upload efetivos: o menor entre o que a aplicação aceita e o
 * que o PHP deste servidor está configurado para receber.
 */
final class UploadLimits
{
    public static function photoBytes(): int
    {
        return min((int) config('clinic.photos.max_upload_kilobytes') * 1024, self::fileBytes());
    }

    /**
     * Maior arquivo que o PHP deste servidor aceita (upload_max_filesize).
     */
    public static function fileBytes(): int
    {
        $php = ini_parse_quantity((string) ini_get('upload_max_filesize'));

        return $php > 0 ? $php : PHP_INT_MAX;
    }

    /**
     * Tamanho máximo do envio inteiro (post_max_size). Zero no PHP significa sem limite.
     */
    public static function requestBytes(): int
    {
        $php = ini_parse_quantity((string) ini_get('post_max_size'));

        return $php > 0 ? $php : PHP_INT_MAX;
    }

    /**
     * Limite por arquivo do PHP, em megabytes, para mensagens ao usuário.
     */
    public static function megabytes(): string
    {
        return rtrim(rtrim(number_format(self::fileBytes() / 1048576, 1, ',', ''), '0'), ',');
    }
}
