<?php

namespace App\Models\ArbitratorFiles;

use App\Enums\Arbitrator\FileStorageType;
use App\Models\Traits\HasArbitratorFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArbitratorFileTradeContract extends Model
{
    use HasArbitratorFile;

    protected $connection = 'auapp';
    protected $table = 'arbitrator_files_trade_contracts';
    protected $guarded = [];

    protected $casts = [
        'provider' => FileStorageType::class,
        'meta' => 'array',
    ];
}
