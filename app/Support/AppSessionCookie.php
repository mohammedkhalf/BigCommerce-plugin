<?php

namespace App\Support;

use Illuminate\Support\Facades\Cookie;

final class AppSessionCookie
{
    public const NAME = 'tamara_app_session';

    public static function queue(string $token): void
    {
        Cookie::queue(cookie(
            self::NAME,
            $token,
            (int) (config('bigcommerce.app_jwt_ttl') / 60),
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax',
        ));
    }
}
