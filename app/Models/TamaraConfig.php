<?php

namespace App\Models;

use App\Enums\TamaraMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TamaraConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_id', 'mode', 'enabled', 'api_token', 'notification_token', 'public_key',
        'currency_allowlist', 'webhook_url', 'last_tested_at',
    ];

    protected $hidden = ['api_token', 'notification_token', 'public_key'];

    protected function casts(): array
    {
        return [
            'mode' => TamaraMode::class,
            'enabled' => 'boolean',
            'api_token' => 'encrypted',
            'notification_token' => 'encrypted',
            'public_key' => 'encrypted',
            'currency_allowlist' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
