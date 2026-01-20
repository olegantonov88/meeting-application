<?php

namespace App\Http\Resources\ArbitratorFile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArbitratorFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => [
                'id' => $this->id,
                'name' => $this->name,
                'provider' => $this->provider,
                'remote_path' => $this->remote_path,
                'size' => $this->size,
                'mime' => $this->mime,
                'meta' => $this->meta,
                'created_at' => $this->created_at?->format('d.m.Y H:i:s'),
                'created_at_data' => $this->created_at,
            ],
        ];
    }
}

