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

    public function customerDisplayName(): ?string
    {
        $snapshot = $this->bc_snapshot ?? [];
        if ($snapshot !== []) {
            $customer = $snapshot['customer'] ?? [];
            $address = $snapshot['billingAddress']
                ?? $snapshot['billing_address']
                ?? $snapshot['consignments'][0]['shippingAddress']
                ?? $snapshot['consignments'][0]['shipping_address']
                ?? [];

            $first = $this->pick($customer, 'firstName', 'first_name')
                ?: $this->pick($address, 'firstName', 'first_name');
            $last = $this->pick($customer, 'lastName', 'last_name')
                ?: $this->pick($address, 'lastName', 'last_name');
            $name = trim("{$first} {$last}");
            if ($name !== '') {
                return $name;
            }

            $email = $this->pick($customer, 'email', 'email')
                ?: $this->pick($address, 'email', 'email');
            if (filled($email)) {
                return $email;
            }
        }

        $consumer = $this->tamara_snapshot['consumer'] ?? null;
        if (is_array($consumer)) {
            $name = trim(
                $this->pick($consumer, 'first_name', 'firstName').' '.
                $this->pick($consumer, 'last_name', 'lastName'),
            );
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    private function pick(array $data, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (filled($data[$key] ?? null)) {
                return (string) $data[$key];
            }
        }

        return '';
    }
}
