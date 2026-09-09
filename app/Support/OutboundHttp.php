<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;

final class OutboundHttp
{
    public static function apply(PendingRequest $request): PendingRequest
    {
        if (app()->environment('local', 'testing')) {
            return $request->withoutVerifying();
        }

        return $request;
    }
}
