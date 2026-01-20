<?php

namespace App\ValueObjects\Meeting\MeetingApplication;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class EfrsbDebtorMessagesMeetingApplicationObject implements Jsonable, Arrayable, Stringable
{
    public $value;

    public static function fromArray($data)
    {
        $instance = new EfrsbDebtorMessagesMeetingApplicationObject();

        if (is_null($data)) return collect();

        $instance->value = collect($data)->map(function ($item) {
            return collect([
                'id' => $item['id'] ?? null,
                'number' => $item['number'] ?? null,
                'title' => $item['title'] ?? null,
                'status' => $item['status'] ?? null,
                'error' => $item['error'] ?? null,
                'size' => $item['size'] ?? null,
                'temp_path_pdf' => $item['temp_path_pdf'] ?? null
            ]);
        });

        return $instance->value;
    }

    public static function fromCollection($data)
    {
        $instance = new EfrsbDebtorMessagesMeetingApplicationObject();

        $instance->value = $data->map(function ($item) {
            return collect([
                'id' => $item['id'] ?? null,
                'number' => $item['number'] ?? null,
                'title' => $item['title'] ?? null,
                'status' => $item['status'] ?? null,
                'error' => $item['error'] ?? null,
                'size' => $item['size'] ?? null,
                'temp_path_pdf' => $item['temp_path_pdf'] ?? null,
            ]);
        });

        return $instance->value;
    }

    public function add($data)
    {
        $this->value->push(collect([
            'id' => $data['id'] ?? null,
            'number' => $data['number'] ?? null,
            'title' => $data['title'] ?? null,
            'status' => $data['status'] ?? null,
            'error' => $data['error'] ?? null,
            'size' => $data['size'] ?? null,
            'temp_path_pdf' => $data['temp_path_pdf'] ?? null,
        ]));

        return $this;
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
        return $this->value->toArray();
    }
}
