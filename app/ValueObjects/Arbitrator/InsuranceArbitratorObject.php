<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class InsuranceArbitratorObject
{
    public static function fromArray($data)
    {
        foreach($data ?? [] as $arr){
            $res[] = self::getArrayfromData($arr);
        }

        return collect($res ?? []);
    }


    private static function getArrayfromData($arr){
        $nullObj = (object) [
            'id' => null,
            'name' => null,
            'contract' => null,
            'start' => null,
            'end' => null,
            'is_basic' => null,
            'debtor_id' => null,
        ];

        return !empty($arr) ? (object) [
            'id' => $arr['id'] ?? null,
            'name' => $arr['name'] ?? null,
            'contract' => $arr['contract'] ?? null,
            'start' => $arr['start'] ?? null,
            'end' => $arr['end'] ?? null,
            'is_basic' => $arr['is_basic'] ?? null,
            'debtor_id' => $arr['debtor_id'] ?? null,
        ] : $nullObj;
    }

}
