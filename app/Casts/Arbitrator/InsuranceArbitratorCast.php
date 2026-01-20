<?php

namespace App\Casts\Arbitrator;

use App\ValueObjects\Arbitrator\InsuranceArbitratorObject;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class InsuranceArbitratorCast implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return InsuranceArbitratorObject::fromArray(json_decode($value, true));
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if(is_null($value)) $value = collect([]);

        if(!$value instanceof InsuranceArbitratorObject && !$value instanceof Collection){
            throw new InvalidArgumentException('The given value is not Array or an InsuranceArbitrator instance.');
        }

        if(is_a($value, 'Illuminate\Database\Eloquent\Collection')) $value = InsuranceArbitratorObject::fromArray($value);
        dd($model->insurance);
        return $value->toJson();
    }
}
