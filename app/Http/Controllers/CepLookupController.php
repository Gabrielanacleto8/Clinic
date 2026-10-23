<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Endereço de um CEP, para o cadastro de paciente preencher logradouro,
 * bairro, cidade e UF. A consulta sai do servidor, porque a CSP não deixa o
 * navegador falar com serviços externos; o resultado fica em cache, pois CEP
 * quase não muda. Nenhum dado do paciente vai junto: só o CEP.
 */
class CepLookupController extends Controller
{
    public const CACHE_DAYS = 30;

    public function __invoke(Request $request, string $cep): JsonResponse
    {
        // Só serve a quem cadastra ou edita paciente.
        abort_unless($request->user()->hasPermission(Permission::PatientsManage), 403);

        $key = "cep.{$cep}";
        $address = Cache::get($key);

        if ($address === null) {
            $address = $this->lookup($cep);

            // Falha do serviço (rede, timeout, 5xx) não vai para o cache: um CEP
            // válido não pode virar "não encontrado" por um mês depois de uma queda.
            if ($address === null) {
                return response()->json(['message' => 'Não foi possível consultar o CEP agora.'], 503);
            }

            Cache::put($key, $address, now()->addDays(self::CACHE_DAYS));
        }

        return $address === false
            ? response()->json(['message' => 'CEP não encontrado.'], 404)
            : response()->json($address);
    }

    /**
     * Endereço do CEP, false quando o serviço diz que ele não existe, ou null
     * quando o serviço falhou e a resposta não é definitiva.
     *
     * @return array{street: string, district: string, city: string, state: string}|false|null
     */
    private function lookup(string $cep): array|false|null
    {
        try {
            $response = Http::timeout(3)->connectTimeout(2)->acceptJson()->get("https://viacep.com.br/ws/{$cep}/json/");
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        if ($response->json('erro') || ! is_string($response->json('localidade'))) {
            return false;
        }

        return [
            'street' => (string) $response->json('logradouro'),
            'district' => (string) $response->json('bairro'),
            'city' => (string) $response->json('localidade'),
            'state' => strtoupper((string) $response->json('uf')),
        ];
    }
}
