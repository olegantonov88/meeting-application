<?php

namespace App\ValueObjects\Meeting\MeetingApplication;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use Stringable;

class ArbitratorFilesMeetingApplicationObject implements Jsonable, Arrayable, Stringable
{
    public $value;

    public static function fromArray($data)
    {
        $instance = new ArbitratorFilesMeetingApplicationObject();

        if (is_null($data)) {
            $instance->value = collect([
                'insurance' => collect(),
                'incoming_letters' => collect(),
                'outgoing_letters' => collect(),
                'inventory' => collect(),
                'estimate' => collect(),
                'trade' => collect(),
                'trade_contract' => collect(),
            ]);
            return $instance;
        }

        $instance->value = collect([
            'insurance' => collect($data['insurance'] ?? []),
            'incoming_letters' => collect($data['incoming_letters'] ?? []),
            'outgoing_letters' => collect($data['outgoing_letters'] ?? []),
            'inventory' => collect($data['inventory'] ?? []),
            'estimate' => collect($data['estimate'] ?? []),
            'trade' => collect($data['trade'] ?? []),
            'trade_contract' => collect($data['trade_contract'] ?? []),
        ]);

        return $instance;
    }

    public static function fromCollection($data)
    {
        $instance = new ArbitratorFilesMeetingApplicationObject();

        if (is_null($data)) {
            $instance->value = collect([
                'insurance' => collect(),
                'incoming_letters' => collect(),
                'outgoing_letters' => collect(),
                'inventory' => collect(),
                'estimate' => collect(),
                'trade' => collect(),
                'trade_contract' => collect(),
            ]);
            return $instance;
        }

        $instance->value = collect([
            'insurance' => $data['insurance'] ?? collect(),
            'incoming_letters' => $data['incoming_letters'] ?? collect(),
            'outgoing_letters' => $data['outgoing_letters'] ?? collect(),
            'inventory' => $data['inventory'] ?? collect(),
            'estimate' => $data['estimate'] ?? collect(),
            'trade' => $data['trade'] ?? collect(),
            'trade_contract' => $data['trade_contract'] ?? collect(),
        ]);

        return $instance;
    }

    public function add($type, $data)
    {
        if (!isset($this->value[$type])) {
            $this->value[$type] = collect();
        }

        $this->value[$type]->push(collect([
            'id' => $data['id'] ?? null,
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? null,
            'status' => $data['status'] ?? null,
            'error' => $data['error'] ?? null,
            'size' => $data['size'] ?? null,
            'temp_path_pdf' => $data['temp_path_pdf'] ?? null
        ]));

        return $this;
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
            'insurance' => $this->value['insurance']->toArray(),
            'incoming_letters' => $this->value['incoming_letters']->toArray(),
            'outgoing_letters' => $this->value['outgoing_letters']->toArray(),
            'inventory' => $this->value['inventory']->toArray(),
            'estimate' => $this->value['estimate']->toArray(),
            'trade' => $this->value['trade']->toArray(),
            'trade_contract' => $this->value['trade_contract']->toArray(),
        ];
    }
}
