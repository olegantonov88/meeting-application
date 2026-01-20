<?php

namespace App\Casts\Arbitrator;

use App\ValueObjects\Arbitrator\ReceptionArbitratorObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ReceptionArbitratorCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return ReceptionArbitratorObject::fromArray(json_decode($value, true));
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if(is_null($value)) $value = [];

        if (!$value instanceof ReceptionArbitratorObject && !is_array($value)) {
            throw new InvalidArgumentException('The given value is not Array or an ReceptionArbitrator instance.');
        }

        if (is_array($value)) $value = ReceptionArbitratorObject::fromArray($value);

        return $value->toJson();
    }
}
