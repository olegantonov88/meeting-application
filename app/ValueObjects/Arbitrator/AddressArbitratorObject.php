<?php

namespace App\ValueObjects\Arbitrator;

use App\Enums\Regions;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class AddressArbitratorObject implements Jsonable, Arrayable, Stringable
{
    public $work_address;
    public $home_address;
    public $post_address;

    public static function fromArray($data)
    {
        $instance = new AddressArbitratorObject();
        $instance->work_address = $instance->getArrayfromData($data, 'work_address');
        $instance->home_address = $instance->getArrayfromData($data, 'home_address');
        $instance->post_address = $instance->getArrayfromData($data, 'post_address');

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
            'work_address' => $this->getArrayfromInstance('work_address'),
            'home_address' => $this->getArrayfromInstance('home_address'),
            'post_address' => $this->getArrayfromInstance('post_address'),
        ];
    }

    public function toText($name = 'post_address', $withCountry = false)
    {
        $address = $this->getArrayfromInstance($name);

        return $this->toTextFromArrayData($address, $withCountry) ?? null;
    }

    public function toTextFromArrayData($data, $name, $withCountry = false)
    {
        if($data['region_id']) $data['region'] = $data['region_id']->text();
        unset($data['region_id']);

        if(!$withCountry) unset($data['country']);

        unset($data['match']);

        $data = array_filter($data);

        return !empty($data) ? implode(', ', $data) : null;
    }


    private function getArrayfromData($arr, $name){
        $nullObj = [
            'match' => false,
            'index' => null,
            'country' => null,
            'region_id' => null,
            'region' => null,
            'city' => null,
            'address' => null,
            'text' => null,
        ];

        $data = !is_null($arr) && array_key_exists($name, $arr) ? [
            'match' => $arr[$name]['match'] ?? false,
            'index' => $arr[$name]['index'] ?? null,
            'country' => $arr[$name]['country'] ?? null,
            'region_id' => !is_null($arr[$name]['region_id']) ? Regions::from($arr[$name]['region_id']) : null,
            'region' => $arr[$name]['region'] ?? null,
            'city' => $arr[$name]['city'] ?? null,
            'address' => $arr[$name]['address'] ?? null,
        ] : $nullObj;

        $data['text'] = $this->toTextFromArrayData($data, $name);

        return (object) $data;
    }

    private function getArrayfromInstance($name){
        return [
            'match' => $this->$name->match ?? false,
            'index' => $this->$name->index ?? null,
            'country' => $this->$name->country ?? null,
            'region_id' => $this->$name->region_id ?? null,
            'region' => $this->$name->region ?? null,
            'city' => $this->$name->city ?? null,
            'address' => $this->$name->address ?? null,
        ];
    }

}
