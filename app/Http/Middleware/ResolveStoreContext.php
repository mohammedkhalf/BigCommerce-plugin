<?php

namespace App\Http\Middleware;

use App\Models\StoreUser;
use App\Services\Auth\AppSessionJwt;
use App\Support\StoreContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveStoreContext
{
    public function __construct(private AppSessionJwt $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new AuthenticationException('App session bearer token is required.');
        }

        $claims = $this->sessions->verify($token);
        $user = StoreUser::query()
            ->with('store')
            ->whereKey($claims['sub'] ?? null)
            ->where('store_id', $claims['store_id'] ?? null)
            ->where('is_active', true)
            ->first();

        if ($user === null || $user->store->uninstalled_at !== null
            || $user->store->store_hash !== ($claims['store_hash'] ?? null)) {
            throw new AuthenticationException('App session is no longer valid.');
        }

        $request->attributes->set(StoreContext::class, new StoreContext($user->store, $user));

        return $next($request);
    }
}
