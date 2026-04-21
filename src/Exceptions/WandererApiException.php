<?php

namespace Guarzo\Seat\WandererSync\Exceptions;

use RuntimeException;
use Throwable;

class WandererApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
