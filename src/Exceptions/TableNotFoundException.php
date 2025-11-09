<?php

namespace NickPotts\Slice\Exceptions;

/**
 * Thrown when a table cannot be resolved by any provider.
 */
class TableNotFoundException extends \Exception
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
