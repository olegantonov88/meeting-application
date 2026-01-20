<?php

namespace App\Casts\Meeting\MeetingApplication;

use App\ValueObjects\Meeting\MeetingApplication\ArbitratorFilesMeetingApplicationObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ArbitratorFilesMeetingApplicationCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return ArbitratorFilesMeetingApplicationObject::fromArray(json_decode($value, true));
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if(is_null($value)) $value = [];

        if (!$value instanceof ArbitratorFilesMeetingApplicationObject && !is_array($value) && !is_a($value, 'Illuminate\Support\Collection')) {
            throw new InvalidArgumentException('The given value is not Array or an ArbitratorFilesMeetingApplication instance.');
        }

        if (is_a($value, 'Illuminate\Support\Collection')) $value = ArbitratorFilesMeetingApplicationObject::fromCollection($value);

        if (is_array($value)) $value = ArbitratorFilesMeetingApplicationObject::fromArray($value);

        return $value->toJson();
    }
}
