<?php

namespace App\Services\Zakaznoe\Exceptions;

use RuntimeException;

/**
 * CRM велела остановиться: сборку перезапустили (409) или реестра больше нет (404).
 * Сообщать о сбое некому и незачем.
 */
class ZakaznoeBuildAborted extends RuntimeException
{
}
