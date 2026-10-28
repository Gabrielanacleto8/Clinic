<?php

namespace App\Http\Controllers;

use App\Services\ClinicLogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Marca da clínica e o manifesto do aplicativo instalável. Públicos: o
 * navegador busca o manifesto e os ícones sem a sessão, e a tela de login
 * mostra o logo. Nada aqui tem dado de paciente.
 */
class ClinicIdentityController extends Controller
{
    /** Cor da barra do aplicativo instalado: o verde dos botões principais (teal-700). */
    private const THEME_COLOR = '#0f766e';

    private const BACKGROUND_COLOR = '#f1f5f9';

    public function manifest(ClinicLogo $logo): JsonResponse
    {
        $version = $logo->version();
        $name = (string) config('app.name');

        return response()->json([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'lang' => 'pt-BR',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => self::BACKGROUND_COLOR,
            'theme_color' => self::THEME_COLOR,
            'icons' => array_map(fn (int $size) => [
                'src' => route('clinic.icon', array_filter(['size' => $size, 'v' => $version]), false),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
            ], array_values(array_diff(ClinicLogo::ICON_SIZES, [180]))),
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function logo(ClinicLogo $logo): Response
    {
        $contents = $logo->logo() ?? abort(404);

        return $this->image($contents, 'image/jpeg');
    }

    public function icon(ClinicLogo $logo, string $size): Response
    {
        return $this->image($logo->icon((int) $size), 'image/png');
    }

    /**
     * As URLs levam a versão do logo, então a imagem pode ficar no cache por um dia.
     */
    private function image(string $contents, string $type): Response
    {
        return response($contents, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
