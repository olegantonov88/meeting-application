<?php

namespace App\Enums\Meeting;

enum MeetingApplicationGenerationTaskStatus: int
{
    case PENDING = 1;
    case GENERATING = 2;
    case COMPLETED = 3;
    case ERROR = 4;

    public function text(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидание',
            self::GENERATING => 'В процессе',
            self::COMPLETED => 'Завершено',
            self::ERROR => 'Ошибка',
        };
    }
}
