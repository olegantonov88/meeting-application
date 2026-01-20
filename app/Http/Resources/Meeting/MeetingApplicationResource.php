<?php

namespace App\Http\Resources\Meeting;

use App\Http\Resources\ArbitratorFile\ArbitratorFileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => [
                'id' => $this->id ?? null,
                'meeting_id' => $this->meeting_id ?? null,
                'start_generation' => $this->start_generation ? $this->start_generation->format('d.m.Y H:i:s') : null,
                'end_generation' => $this->end_generation ? $this->end_generation->format('d.m.Y H:i:s') : null,
                'latest_status' => $this->latest_status ? $this->latest_status->value : null,
                'statuses' => $this->statuses ?? [],
                'arbitrator_files' => $this->arbitrator_files ? $this->arbitrator_files->toArray() : [],
                'efrsb_debtor_messages' => $this->efrsb_debtor_messages ? $this->efrsb_debtor_messages->toArray() : [],

                'latest_status_name' => $this->latest_status ? $this->latest_status->name : null,
                'latest_status_text' => $this->latest_status ? $this->latest_status->text() : null,
            ],
            'has_arbitrator_files' => $this->arbitrator_files_exists ? $this->arbitrator_files_exists > 0 : false,
            'arbitrator_files' => ArbitratorFileResource::collection($this->whenLoaded('arbitratorFiles')),
        ];
    }
}
