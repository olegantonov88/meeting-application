<?php

namespace App\Enums\EfrsbMessage;

/**
 * Формат тела сообщения ЕФРСБ, полученного воркером с Федресурса.
 * Значения пишет воркер efrsb-debtor-message, менять их нельзя
 */
enum EfrsbDebtorMessageBodyFormat: int
{
    case HTML = 1;
    case JSON = 2;

    public function text()
    {
        return match ($this->value) {
            self::HTML->value => 'HTML',
            self::JSON->value => 'JSON',
        };
    }
}
