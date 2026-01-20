<?php

namespace App\ValueObjects\Arbitrator;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Stringable;

class MetadataArbitratorSignatureObject implements Jsonable, Arrayable, Stringable
{
    public $original_width;
    public $original_height;
    public $original_dpi_x;
    public $original_dpi_y;
    public $original_physical_width;
    public $original_physical_height;
    public $original_physical_width_mm;
    public $original_physical_height_mm;
    public $processed_width;
    public $processed_height;
    public $processed_dpi_x;
    public $processed_dpi_y;
    public $processed_physical_width;
    public $processed_physical_height;
    public $processed_physical_width_mm;
    public $processed_physical_height_mm;
    public $was_resized;
    public $file_size_before;
    public $file_size_after;

    public static function fromArray($data)
    {
        $instance = new MetadataArbitratorSignatureObject();
        $instance->original_width = $data['original_width'] ?? null;
        $instance->original_height = $data['original_height'] ?? null;
        $instance->original_dpi_x = $data['original_dpi_x'] ?? null;
        $instance->original_dpi_y = $data['original_dpi_y'] ?? null;
        $instance->original_physical_width = $data['original_physical_width'] ?? null;
        $instance->original_physical_height = $data['original_physical_height'] ?? null;
        $instance->original_physical_width_mm = $data['original_physical_width_mm'] ?? null;
        $instance->original_physical_height_mm = $data['original_physical_height_mm'] ?? null;
        $instance->processed_width = $data['processed_width'] ?? null;
        $instance->processed_height = $data['processed_height'] ?? null;
        $instance->processed_dpi_x = $data['processed_dpi_x'] ?? null;
        $instance->processed_dpi_y = $data['processed_dpi_y'] ?? null;
        $instance->processed_physical_width = $data['processed_physical_width'] ?? null;
        $instance->processed_physical_height = $data['processed_physical_height'] ?? null;
        $instance->processed_physical_width_mm = $data['processed_physical_width_mm'] ?? null;
        $instance->processed_physical_height_mm = $data['processed_physical_height_mm'] ?? null;
        $instance->was_resized = $data['was_resized'] ?? false;
        $instance->file_size_before = $data['file_size_before'] ?? null;
        $instance->file_size_after = $data['file_size_after'] ?? null;

        return $instance;
    }

    /**
     * Получить физическую ширину изображения в дюймах (оригинал)
     */
    public function getOriginalPhysicalWidth(): float
    {
        if (!$this->original_dpi_x || $this->original_dpi_x <= 0) {
            return 0;
        }
        return $this->original_width / $this->original_dpi_x;
    }

    /**
     * Получить физическую высоту изображения в дюймах (оригинал)
     */
    public function getOriginalPhysicalHeight(): float
    {
        if (!$this->original_dpi_y || $this->original_dpi_y <= 0) {
            return 0;
        }
        return $this->original_height / $this->original_dpi_y;
    }

    /**
     * Получить физическую ширину изображения в дюймах (обработанное)
     */
    public function getProcessedPhysicalWidth(): float
    {
        if (!$this->processed_dpi_x || $this->processed_dpi_x <= 0) {
            return 0;
        }
        return $this->processed_width / $this->processed_dpi_x;
    }

    /**
     * Получить физическую высоту изображения в дюймах (обработанное)
     */
    public function getProcessedPhysicalHeight(): float
    {
        if (!$this->processed_dpi_y || $this->processed_dpi_y <= 0) {
            return 0;
        }
        return $this->processed_height / $this->processed_dpi_y;
    }

    /**
     * Получить процент сжатия файла
     */
    public function getCompressionRatio(): float
    {
        if (!$this->file_size_before || $this->file_size_before <= 0) {
            return 0;
        }
        return round((1 - $this->file_size_after / $this->file_size_before) * 100, 2);
    }

    /**
     * Получить информацию о размерах в читаемом формате
     */
    public function getDimensionsInfo(): string
    {
        if (!$this->processed_width || !$this->processed_height) {
            return 'Размеры не определены';
        }

        $info = "{$this->processed_width}×{$this->processed_height} px";

        if ($this->processed_dpi_x && $this->processed_dpi_y) {
            $info .= " ({$this->processed_dpi_x}×{$this->processed_dpi_y} DPI)";
        }

        if ($this->was_resized && $this->original_width && $this->original_height) {
            $info .= " (сжато с {$this->original_width}×{$this->original_height})";
        }

        return $info;
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
            'original_width' => $this->original_width ?? null,
            'original_height' => $this->original_height ?? null,
            'original_dpi_x' => $this->original_dpi_x ?? null,
            'original_dpi_y' => $this->original_dpi_y ?? null,
            'original_physical_width' => $this->original_physical_width ?? null,
            'original_physical_height' => $this->original_physical_height ?? null,
            'original_physical_width_mm' => $this->original_physical_width_mm ?? null,
            'original_physical_height_mm' => $this->original_physical_height_mm ?? null,
            'processed_width' => $this->processed_width ?? null,
            'processed_height' => $this->processed_height ?? null,
            'processed_dpi_x' => $this->processed_dpi_x ?? null,
            'processed_dpi_y' => $this->processed_dpi_y ?? null,
            'processed_physical_width' => $this->processed_physical_width ?? null,
            'processed_physical_height' => $this->processed_physical_height ?? null,
            'processed_physical_width_mm' => $this->processed_physical_width_mm ?? null,
            'processed_physical_height_mm' => $this->processed_physical_height_mm ?? null,
            'was_resized' => $this->was_resized ?? false,
            'file_size_before' => $this->file_size_before ?? null,
            'file_size_after' => $this->file_size_after ?? null,
        ];
    }
}
