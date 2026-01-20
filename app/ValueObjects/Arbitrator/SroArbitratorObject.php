<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class SroArbitratorObject implements Jsonable, Arrayable, Stringable
{
    public $shortname;
    public $fullname;
    public $address;
    public $inn;
    public $ogrn;
    public $regnum;
    public $regdate;
    public $member_number;

    public static function fromArray($data)
    {
        $instance = new SroArbitratorObject();
        $instance->shortname = $data['shortname'] ?? null;
        $instance->fullname = $data['fullname'] ?? null;
        $instance->address = $data['address'] ?? null;
        $instance->inn = $data['inn'] ?? null;
        $instance->ogrn = $data['ogrn'] ?? null;
        $instance->regnum = $data['regnum'] ?? null;
        $instance->regdate = $data['regdate'] ?? null;
        $instance->member_number = $data['member_number'] ?? null;

        return $instance;
    }

    public function toJson($options = 0)
    {
        return json_encode($this);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function toArray()
    {
        return $this;
    }

}
