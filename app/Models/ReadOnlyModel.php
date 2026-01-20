<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Базовая модель только для чтения
 * Запрещает все операции записи (create, update, delete)
 */
abstract class ReadOnlyModel extends Model
{
    /**
     * Запрет на сохранение модели (только чтение)
     */
    public function save(array $options = [])
    {
        throw new \RuntimeException('Модель ' . static::class . ' доступна только для чтения');
    }

    /**
     * Запрет на обновление модели (только чтение)
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \RuntimeException('Модель ' . static::class . ' доступна только для чтения');
    }

    /**
     * Запрет на удаление модели (только чтение)
     */
    public function delete()
    {
        throw new \RuntimeException('Модель ' . static::class . ' доступна только для чтения');
    }

    /**
     * Запрет на принудительное удаление модели (только чтение)
     */
    public function forceDelete()
    {
        throw new \RuntimeException('Модель ' . static::class . ' доступна только для чтения');
    }

    /**
     * Запрет на создание модели (только чтение)
     */
    public static function create(array $attributes = [])
    {
        throw new \RuntimeException('Модель ' . static::class . ' доступна только для чтения');
    }
}
