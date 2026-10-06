<?php

namespace App\Models;

use App\Enums\ApiLogAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'store_id',
        'bc_order_id',
        'action',
        'request_payload',
        'response_payload',
        'http_status',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => ApiLogAction::class,
            'request_payload' => 'array',
            'response_payload' => 'array',
            'logged_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
