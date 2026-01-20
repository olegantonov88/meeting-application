<?php

namespace App\ValueObjects\Arbitrator;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class ReceptionArbitratorObject implements Jsonable, Arrayable, Stringable
{
    public $timeStart;
    public $timeEnd;

    public static function fromArray($data)
    {
        $instance = new ReceptionArbitratorObject();
        $instance->timeStart = !is_null($data) && array_key_exists('timeStart', $data) ? $data['timeStart'] ?? null : null;
        $instance->timeEnd = !is_null($data) && array_key_exists('timeEnd', $data) ? $data['timeEnd'] ?? null : null;

        return $instance;
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray());
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function toArray()
    {
        return [
            'timeStart' => $this->timeStart ?? null,
            'timeEnd' => $this->timeEnd ?? null,
        ];
    }

}
