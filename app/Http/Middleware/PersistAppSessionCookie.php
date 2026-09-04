<?php

namespace App\Http\Middleware;

use App\Support\AppSessionCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class PersistAppSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (is_string($token = $request->bearerToken()) && $token !== '') {
            AppSessionCookie::queue($token);
        }

        return $response;
    }
}
