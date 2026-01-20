<?php

namespace App\Models\EfrsbMessage;

use App\Models\ReadOnlyModel;

class EfrsbDebtorMessage extends ReadOnlyModel
{
    protected $connection = 'auapp';
    protected $table = 'efrsb_debtor_messages';
    protected $guarded = [];

    protected $casts = [
        'publish_date' => 'datetime',
    ];

    public function debtor()
    {
        // Debtor модель будет доступна через БД auapp
        return $this->belongsTo(\Illuminate\Database\Eloquent\Model::class, 'debtor_id');
    }
}
