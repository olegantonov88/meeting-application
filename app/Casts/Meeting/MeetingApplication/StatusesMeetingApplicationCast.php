<?php

namespace App\Casts\Meeting\MeetingApplication;

use App\ValueObjects\Meeting\MeetingApplication\StatusesMeetingApplicationObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class StatusesMeetingApplicationCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return StatusesMeetingApplicationObject::fromArray(json_decode($value, true));
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (is_null($value)) $value = [];

        if (is_a($value, 'Illuminate\Support\Collection')) {
            return $value->toJson();
        }

        if (is_array($value)) {
            $value = StatusesMeetingApplicationObject::fromArray($value);
        }

        if ($value instanceof StatusesMeetingApplicationObject) {
            return $value->toJson();
        }

        throw new InvalidArgumentException('The given value is not Array, Collection or an StatusesMeetingApplication instance.');
    }

}
