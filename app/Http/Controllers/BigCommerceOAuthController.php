<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreUser;
use App\Services\Auth\AppSessionJwt;
use App\Services\BigCommerce\OAuthService;
use App\Support\AppSessionCookie;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

final readonly class BigCommerceOAuthController
{
    public function __construct(
        private OAuthService $oauth,
        private AppSessionJwt $sessions,
    ) {}

    public function auth(Request $request): InertiaResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'context' => ['required', 'string', 'regex:/^stores\/[a-z0-9]+$/i'],
            'scope' => ['required', 'string', 'max:4096'],
        ]);

        // Increase the script time limit for this request so long-running upstream
        // requests (token exchange, API calls) can complete without PHP killing
        // the process prematurely during local development.
        @set_time_limit(60);

        try {
            $session = $this->oauth->install($input['code'], $input['context'], $input['scope']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Upstream/network failure — log and return a 502 to indicate a bad gateway.
            \Log::error('BigCommerce connection error during install', ['exception' => $e]);
            abort(502, 'Failed to contact BigCommerce. Please try again.');
        } catch (\Throwable $e) {
            \Log::error('Unexpected error during BigCommerce install', ['exception' => $e]);
            abort(500, 'Unexpected server error. Check logs for details.');
        }

        return $this->render($session['store'], $session['user'], 'Onboarding');
    }

    public function load(Request $request): InertiaResponse
    {
        // Accept either `signed_payload_jwt` or `signed_payload` as some callers use a different param name.
        $token = $request->input('signed_payload_jwt') ?? $request->input('signed_payload');
        if (! is_string($token) || trim($token) === '' || strlen($token) > 8192) {
            throw \Illuminate\Validation\ValidationException::withMessages(['signed_payload' => 'The signed payload is required.']);
        }
        $session = $this->oauth->load($token);
        $component = $session['store']->tamaraConfig()->exists() ? 'Dashboard' : 'Onboarding';

        return $this->render($session['store'], $session['user'], $component);
    }

    public function uninstall(Request $request): Response
    {
        $input = $request->validate(['signed_payload_jwt' => ['required', 'string', 'max:8192']]);
        $this->oauth->uninstall($input['signed_payload_jwt']);

        return response()->noContent();
    }

    public function removeUser(Request $request): Response
    {
        $input = $request->validate(['signed_payload_jwt' => ['required', 'string', 'max:8192']]);
        $this->oauth->removeUser($input['signed_payload_jwt']);

        return response()->noContent();
    }

    private function render(Store $store, StoreUser $user, string $component): InertiaResponse
    {
        $config = $store->tamaraConfig()->first();
        $metadata = $store->metadata ?? [];
        $appToken = $this->sessions->issue($store, $user);

        AppSessionCookie::queue($appToken);

        return Inertia::render($component, [
            'appToken' => $appToken,
            'store' => [
                'id' => $store->id,
                'hash' => $store->store_hash,
                'name' => $metadata['name'] ?? null,
                'environment' => $config?->mode?->value === 'production' ? 'live' : 'sandbox',
            ],
            'connected' => $config !== null,
            'environment' => $config?->mode?->value === 'production' ? 'live' : 'sandbox',
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'isOwner' => $user->is_owner,
            ],
        ]);
    }
}
