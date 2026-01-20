<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingApplicationStatusNotification implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $title,
        public string $message,
        public string $type,
        public ?int $life = null
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('Pusher.Notification.' . $this->userId);
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'life' => $this->life,
            'created_at' => now()->toDateTimeString(),
        ];
    }
}
