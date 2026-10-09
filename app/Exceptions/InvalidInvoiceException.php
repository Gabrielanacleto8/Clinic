<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O arquivo enviado não pôde ser lido como nota fiscal. A mensagem é exibida ao usuário.
 */
class InvalidInvoiceException extends RuntimeException {}
