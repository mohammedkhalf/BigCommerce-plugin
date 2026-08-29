<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_hash', 'account_uuid', 'name', 'currency', 'timezone', 'locale',
        'access_token', 'scopes', 'metadata', 'installed_at', 'uninstalled_at',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'scopes' => 'array',
            'metadata' => 'array',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(StoreUser::class);
    }

    public function tamaraConfig(): HasOne
    {
        return $this->hasOne(TamaraConfig::class);
    }

    public function paymentSessions(): HasMany
    {
        return $this->hasMany(PaymentSession::class);
    }

    public function registeredResources(): HasMany
    {
        return $this->hasMany(RegisteredResource::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}
