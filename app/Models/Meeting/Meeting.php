<?php

namespace App\Models\Meeting;

use App\Models\Procedure\Procedure;
use App\Models\ReadOnlyModel;

class Meeting extends ReadOnlyModel
{
    protected $connection = 'auapp';
    protected $table = 'meetings';
    protected $guarded = [];

    protected $casts = [
    ];

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }


    public function meetingApplications()
    {
        return $this->hasMany(MeetingApplication::class);
    }

}
