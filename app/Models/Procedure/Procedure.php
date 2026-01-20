<?php

namespace App\Models\Procedure;

use App\Models\Arbitrator\Arbitrator;
use App\Models\ReadOnlyModel;

class Procedure extends ReadOnlyModel
{
    protected $connection = 'auapp';
    protected $table = 'procedures';
    protected $guarded = [];

    public function arbitrator()
    {
        return $this->belongsTo(Arbitrator::class);
    }

}
