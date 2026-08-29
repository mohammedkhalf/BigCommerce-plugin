<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors 'self' https://*.mybigcommerce.com https://*.bigcommerce.com"
        );

        return $response;
    }
}
