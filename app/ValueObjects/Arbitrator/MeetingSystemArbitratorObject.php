<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;
use Illuminate\Support\Facades\Crypt;

class MeetingSystemArbitratorObject implements Jsonable, Arrayable, Stringable
{
    private $key;
    public $accreditation_id;
    public $accreditation_number;
    public $accreditation_valid_to;

    public static function fromArray($data)
    {
        $instance = new MeetingSystemArbitratorObject();
        $instance->setKey($data['key'] ?? null);
        $instance->accreditation_id = $data['accreditation_id'] ?? null;
        $instance->accreditation_number = $data['accreditation_number'] ?? null;
        $instance->accreditation_valid_to = $data['accreditation_valid_to'] ?? null;

        return $instance;
    }

    public function setKey($key)
    {
        $this->key = $key ? Crypt::encryptString($key) : null;
    }

    public function getKey()
    {
        return $this->key ? Crypt::decryptString($this->key) : null;
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
            'key' => $this->getKey(),
            'accreditation_id' => $this->accreditation_id,
            'accreditation_number' => $this->accreditation_number,
            'accreditation_valid_to' => $this->accreditation_valid_to,
        ];
    }

}
