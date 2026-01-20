<?php

namespace App\Models\Meeting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingApplicationGenerationTask extends Model
{
    protected $connection = 'auapp';
    protected $table = 'meeting_application_generation_tasks';

    protected $fillable = [
        'meeting_application_id',
        'user_id',
        'started_at',
        'finished_at',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'status' => \App\Enums\Meeting\MeetingApplicationGenerationTaskStatus::class,
    ];

    /**
     * Связь с приложением к собранию
     */
    public function meetingApplication(): BelongsTo
    {
        return $this->belongsTo(MeetingApplication::class, 'meeting_application_id');
    }

    /**
     * Связь с пользователем
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
