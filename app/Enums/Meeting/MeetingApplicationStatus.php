<?php

namespace App\Enums\Meeting;

enum MeetingApplicationStatus: int
{
    case DRAFT = 1;
    case GENERATING = 2;
    case GENERATED = 3;
    case PARTIALLY_GENERATED = 4;

    case ERROR = 99;

    public function text()
    {
        return match ($this->value) {
            self::DRAFT->value => 'Ожидание генерации',
            self::GENERATING->value => 'Генерация',
            self::GENERATED->value => 'Сгенерировано',
            self::PARTIALLY_GENERATED->value => 'Сгенерировано частично',
            self::ERROR->value => 'Ошибка',
        };
    }

    static public function textAllTypeArray()
    {
        return [
            ['id' => self::DRAFT->value, 'name' => self::DRAFT->name, 'value' => 'Ожидание генерации'],
            ['id' => self::GENERATING->value, 'name' => self::GENERATING->name, 'value' => 'Генерация'],
            ['id' => self::GENERATED->value, 'name' => self::GENERATED->name, 'value' => 'Сгенерировано'],
            ['id' => self::PARTIALLY_GENERATED->value, 'name' => self::PARTIALLY_GENERATED->name, 'value' => 'Сгенерировано частично'],
            ['id' => self::ERROR->value, 'name' => self::ERROR->name, 'value' => 'Ошибка'],
        ];
    }

}

