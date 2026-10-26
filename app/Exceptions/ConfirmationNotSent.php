<?php

namespace App\Exceptions;

use Exception;

/**
 * A confirmação de consulta não pôde ser enviada; `status` é o código HTTP a devolver.
 */
class ConfirmationNotSent extends Exception
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
