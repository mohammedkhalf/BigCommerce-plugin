<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreUser extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_id', 'bigcommerce_user_id', 'email', 'name', 'locale', 'is_owner', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_owner' => 'boolean', 'is_active' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
