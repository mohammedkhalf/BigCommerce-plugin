<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\StoreUser;
use App\Services\Auth\AppSessionJwt;
use App\Services\BigCommerce\OAuthService;
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

        $session = $this->oauth->install($input['code'], $input['context'], $input['scope']);

        return $this->render($session['store'], $session['user'], 'Onboarding');
    }

    public function load(Request $request): InertiaResponse
    {
        $input = $request->validate(['signed_payload_jwt' => ['required', 'string', 'max:8192']]);
        $session = $this->oauth->load($input['signed_payload_jwt']);
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

        return Inertia::render($component, [
            'appToken' => $this->sessions->issue($store, $user),
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
