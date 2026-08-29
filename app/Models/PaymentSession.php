<?php

namespace App\Models;

use App\Enums\PaymentSessionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_id', 'bc_checkout_id', 'bc_order_id', 'checkout_token', 'tamara_order_id',
        'tamara_checkout_id', 'status', 'amount', 'currency', 'bc_snapshot', 'tamara_snapshot',
        'authorised_at', 'captured_at', 'cancelled_at', 'failed_at', 'expires_at',
    ];

    protected $hidden = ['checkout_token'];

    protected function casts(): array
    {
        return [
            'status' => PaymentSessionStatus::class,
            'amount' => 'decimal:3',
            'checkout_token' => 'encrypted',
            'bc_snapshot' => 'array',
            'tamara_snapshot' => 'array',
            'authorised_at' => 'datetime',
            'captured_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'failed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
