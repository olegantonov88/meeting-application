<?php

namespace App\Enums\Arbitrator;

enum FileStorageType: int
{
    case YANDEX_DISK = 1;
    case ONB_STORAGE = 2;

    public function text(): string
    {
        return match ($this->value) {
            self::YANDEX_DISK->value => 'Яндекс Диск',
            self::ONB_STORAGE->value => 'ОнБанкрот',
        };
    }

    public static function textAllTypeArray(): array
    {
        return [
            ['id' => self::YANDEX_DISK->value, 'name' => self::YANDEX_DISK->name, 'value' => self::YANDEX_DISK->text()],
            ['id' => self::ONB_STORAGE->value, 'name' => self::ONB_STORAGE->name, 'value' => self::ONB_STORAGE->text()],
        ];
    }

    /**
     * Конвертирует строковое значение провайдера в Enum
     */
    public static function fromString(string $value): ?self
    {
        return match ($value) {
            'yandex_disk' => self::YANDEX_DISK,
            'onb_storage' => self::ONB_STORAGE,
            default => null,
        };
    }

    /**
     * Возвращает строковое значение для использования в конфигах
     */
    public function toString(): string
    {
        return match ($this) {
            self::YANDEX_DISK => 'yandex_disk',
            self::ONB_STORAGE => 'onb_storage',
        };
    }
}
