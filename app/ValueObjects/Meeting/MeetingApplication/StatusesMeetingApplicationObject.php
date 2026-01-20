<?php

namespace App\ValueObjects\Meeting\MeetingApplication;

use App\Enums\Meeting\MeetingApplicationStatus;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Carbon;
use Stringable;

class StatusesMeetingApplicationObject implements Jsonable, Arrayable, Stringable
{
    public $value;

    public static function fromArray($data)
    {
        $instance = new StatusesMeetingApplicationObject();

        if (is_null($data)) return collect();

        $instance->value = collect($data)->map(function ($item) {
            $date = $item['date'] ? Carbon::parse($item['date']) : null;
            $status = $item['status'] ? MeetingApplicationStatus::from($item['status']) : null;
            $userText = $item['user_text'] ? $item['user_text'] : null;
            $systemText = $item['system_text'] ? $item['system_text'] : null;

            return collect([
                'id' => $item['id'] ?? null,
                'status' => $status,
                'status_name' => $status ? $status->name : null,
                'status_text' => $status ? $status->text() : null,
                'date' => $date,
                'date_format' => $date ? $date->format('d.m.Y') : null,
                'user_text' => $userText,
                'system_text' => $systemText,
            ]);
        });

        return $instance->value;
    }

    public static function fromCollection($data)
    {
        $instance = new StatusesMeetingApplicationObject();

        $instance->value = $data->map(function ($item) {
            return collect([
                'id' => $item['id'] ?? null,
                'status' => $item['status'] ?? null,
                'date' => $item['date'] ?? null,
                'user_text' => $item['user_text'] ?? null,
                'system_text' => $item['system_text'] ?? null,
            ]);
        });

        return $instance->value;
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
        return $this->value;
    }
}
