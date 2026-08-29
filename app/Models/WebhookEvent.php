<?php

namespace App\Models;

use App\Enums\WebhookSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_id', 'payment_session_id', 'source', 'external_id', 'event_type', 'payload',
        'processing_status', 'attempts', 'received_at', 'processed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'source' => WebhookSource::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class);
    }
}
