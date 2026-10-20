<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Produto que não pode sair para uso: lote vencido, produto inativo, unidade
 * fora do prazo depois de aberta ou sem unidade lacrada para abrir. A
 * mensagem é para o usuário e nunca leva dado de paciente.
 */
class UnusableStockException extends RuntimeException {}
