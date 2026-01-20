<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class ContactsArbitratorObject implements Jsonable, Arrayable, Stringable
{
    public $mobile;
    public $phone;
    public $fax;
    public $email;

    public static function fromArray($data)
    {
        $instance = new ContactsArbitratorObject();
        $instance->mobile = $data['mobile'] ?? null;
        $instance->phone = $data['phone'] ?? null;
        $instance->fax = $data['fax'] ?? null;
        $instance->email = $data['email'] ?? null;

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
