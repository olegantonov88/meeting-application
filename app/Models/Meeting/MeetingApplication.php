<?php

namespace App\Models\Meeting;

use App\Casts\Meeting\MeetingApplication\ArbitratorFilesMeetingApplicationCast;
use App\Casts\Meeting\MeetingApplication\EfrsbDebtorMessagesMeetingApplicationCast;
use App\Casts\Meeting\MeetingApplication\StatusesMeetingApplicationCast;
use App\Enums\Meeting\MeetingApplicationStatus;
use App\Models\ArbitratorFiles\ArbitratorFileMeetingApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingApplication extends Model
{
    protected $connection = 'auapp';
    protected $table = 'meeting_applications';
    protected $guarded = [];

    protected $casts = [
        'latest_status' => MeetingApplicationStatus::class,
        'statuses' => StatusesMeetingApplicationCast::class,
        'arbitrator_files' => ArbitratorFilesMeetingApplicationCast::class,
        'efrsb_debtor_messages' => EfrsbDebtorMessagesMeetingApplicationCast::class,
        'start_generation' => 'datetime',
        'end_generation' => 'datetime',
        'meta' => 'array',
    ];

    public function arbitratorFiles(): HasMany
    {
        return $this->hasMany(ArbitratorFileMeetingApplication::class);
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function addStatus($status, $userText = null, $systemText = null)
    {
        if ($status instanceof MeetingApplicationStatus) {
            $this->latest_status = $status;
            $status = $status->value;
        } else {
            $statusEnum = MeetingApplicationStatus::tryFrom($status);
            if ($statusEnum) {
                $this->latest_status = $statusEnum;
            }
        }

        $this->statuses->push(collect([
            'id' => $this->statuses->max('id') ? $this->statuses->max('id') + 1 : 1,
            'status' => $status,
            'date' => now(),
            'user_text' => $userText,
            'system_text' => $systemText,
        ]));

        return $this;
    }
}
