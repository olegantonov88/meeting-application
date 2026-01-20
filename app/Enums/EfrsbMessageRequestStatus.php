<?php

namespace App\Enums;

enum EfrsbMessageRequestStatus: int
{
    case PENDING = 1;
    case COMPLETED = 2;
    case ERROR = 3;
    case TIMEOUT = 4;

    public function text(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидание',
            self::COMPLETED => 'Завершено',
            self::ERROR => 'Ошибка',
            self::TIMEOUT => 'Таймаут',
        };
    }
}
