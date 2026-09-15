<?php

namespace App\Services\Zakaznoe\Exceptions;

use RuntimeException;

/**
 * CRM ответила 422 - данные события не прошли её контракт. Подробности в логе.
 */
class ZakaznoeCallbackRejected extends RuntimeException
{
}
