<?php

namespace App\Enums\User;

enum UserSex: int
{
    case MALE = 1;
    case FEMALE = 2;

    public function text()
    {
        return match ($this->value) {
            self::MALE->value => 'Мужской',
            self::FEMALE->value => 'Женский',
        };
    }

    static public function textAllType()
    {
        return [

            self::MALE->value => 'Мужской',
            self::FEMALE->value => 'Женский',
        ];
    }

    static public function textAllTypeArray()
    {
        return [
            ['id' => self::MALE->value, 'name' => self::MALE->name, 'value' => 'Мужской'],
            ['id' => self::FEMALE->value, 'name' => self::FEMALE->name, 'value' => 'Женский'],
        ];
    }
}
