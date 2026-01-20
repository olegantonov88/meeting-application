<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class SettingsArbitratorSignatureObject implements Jsonable, Arrayable, Stringable
{
    public $left;
    public $top;
    public $right;
    public $bottom;
    public $width;
    public $height;

    public static function fromArray($data)
    {
        $instance = new SettingsArbitratorSignatureObject();
        $instance->left = $data['left'] ?? null;
        $instance->top = $data['top'] ?? null;
        $instance->right = $data['right'] ?? null;
        $instance->bottom = $data['bottom'] ?? null;
        $instance->width = $data['width'] ?? null;
        $instance->height = $data['height'] ?? null;

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
            'left' => $this->left ?? null,
            'top' => $this->top ?? null,
            'right' => $this->right ?? null,
            'bottom' => $this->bottom ?? null,
            'width' => $this->width ?? null,
            'height' => $this->height ?? null,
        ];
    }
}
