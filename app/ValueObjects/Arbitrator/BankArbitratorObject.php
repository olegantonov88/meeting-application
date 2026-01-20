<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class BankArbitratorObject implements Jsonable, Arrayable, Stringable
{
    public $name;
    public $bik;
    public $ks;
    public $rs;

    public static function fromArray($data)
    {
        $instance = new BankArbitratorObject();
        $instance->name = $data['name'] ?? null;
        $instance->bik = $data['bik'] ?? null;
        $instance->ks = $data['ks'] ?? null;
        $instance->rs = $data['rs'] ?? null;
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
        return [
            'name' => $this->name ?? null,
            'bik' => $this->bik ?? null,
            'ks' => $this->ks ?? null,
            'rs' => $this->rs ?? null,
        ];
    }


    public function toText()
    {
        $requisites = array_filter([
            $this->rs ? "Р/С: {$this->rs}" : null,
            $this->name ? "в {$this->name}" : null,
            $this->bik ? "БИК: {$this->bik}" : null,
            $this->ks ? "К/С: {$this->ks}" : null,
        ]);

        return count($requisites) > 0 ? implode(', ', $requisites) : null;
    }
}
