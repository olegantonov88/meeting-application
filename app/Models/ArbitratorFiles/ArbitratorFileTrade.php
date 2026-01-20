<?php

namespace App\Models\ArbitratorFiles;

use App\Enums\Arbitrator\FileStorageType;
use App\Models\Traits\HasArbitratorFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArbitratorFileTrade extends Model
{
    use HasArbitratorFile;

    protected $connection = 'auapp';
    protected $table = 'arbitrator_files_trades';
    protected $guarded = [];

    protected $casts = [
        'provider' => FileStorageType::class,
        'meta' => 'array',
    ];
}
