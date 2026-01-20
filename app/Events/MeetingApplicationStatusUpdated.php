<?php

namespace App\Events;

use App\Http\Resources\Meeting\MeetingApplicationResource;
use App\Models\Meeting\MeetingApplication;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MeetingApplicationStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public MeetingApplication $meetingApplication
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('Meeting.Application.Generate.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'meeting.application.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'data' => [
                'id' => $this->meetingApplication->id ?? null,
                'latest_status' => $this->meetingApplication->latest_status?->value,
                'latest_status_text' => $this->meetingApplication->latest_status?->text(),
            ],
        ];
    }
}
