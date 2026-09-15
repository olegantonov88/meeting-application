<?php

namespace App\Services\Zakaznoe\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Сборку продолжать нельзя. Сообщение уходит пользователю как есть,
 * код - в CRM для разбора.
 */
class ZakaznoeBuildException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
