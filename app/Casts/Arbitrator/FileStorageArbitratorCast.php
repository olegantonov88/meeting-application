<?php

namespace App\Casts\Arbitrator;

use App\ValueObjects\Arbitrator\FileStorageArbitratorObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class FileStorageArbitratorCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return FileStorageArbitratorObject::fromArray(json_decode($value ?? '[]', true));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (is_null($value)) {
            return null;
        }

        if (!$value instanceof FileStorageArbitratorObject && !is_array($value)) {
            throw new InvalidArgumentException('The given value is not Array or a FileStorageArbitratorObject instance.');
        }

        if (is_array($value)) {
            $value = FileStorageArbitratorObject::fromArray($value);
        }

        return json_encode($value->toStorageArray());
    }
}

