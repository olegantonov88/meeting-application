<?php

namespace App\Models\EfrsbMessage;

use App\Enums\EfrsbMessage\EfrsbDebtorMessageBodyFormat;
use App\Models\ReadOnlyModel;
use Illuminate\Database\Eloquent\Builder;

class EfrsbDebtorMessage extends ReadOnlyModel
{
    protected $connection = 'auapp';
    protected $table = 'efrsb_debtor_messages';
    protected $guarded = [];

    protected $casts = [
        'publish_date' => 'datetime',
        'body_format' => EfrsbDebtorMessageBodyFormat::class,
        'body_requested_at' => 'datetime',
    ];

    public function debtor()
    {
        // Debtor модель будет доступна через БД auapp
        return $this->belongsTo(\Illuminate\Database\Eloquent\Model::class, 'debtor_id');
    }

    /**
     * Данные сообщения получены: html или json (body_format пишет воркер).
     * Старые записи без body_format - по body_html
     */
    public function hasBody(): bool
    {
        return $this->body_format !== null || !empty($this->body_html);
    }

    /**
     * Сообщения с полученными данными - то же условие, что hasBody()
     */
    public function scopeWithBody(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNotNull('body_format')
            ->orWhere(fn (Builder $query) => $query->whereNotNull('body_html')->where('body_html', '!=', '')));
    }
}
