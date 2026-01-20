<?php

namespace App\Models\Traits;

use App\Models\User;
use App\Models\Workspace\Workspace;
use App\Models\Arbitrator\Arbitrator;
use App\Models\Procedure\Procedure;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasArbitratorFile
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function arbitrator(): BelongsTo
    {
        return $this->belongsTo(Arbitrator::class);
    }
}


