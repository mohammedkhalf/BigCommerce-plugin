<?php

namespace App\Http\Controllers;

use App\Enums\TamaraMode;
use App\Models\TamaraConfig;
use App\Services\Tamara\TamaraClient;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class TamaraSettingsController
{
    public function __construct(private TamaraClient $client) {}

    public function save(Request $request): JsonResponse
    {
        $input = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'live'])],
            'merchantToken' => ['nullable', 'string', 'min:10', 'max:4096'],
            'notificationToken' => ['nullable', 'string', 'min:16', 'max:4096'],
            'publicKey' => ['nullable', 'string', 'max:16384'],
            'currencies' => ['nullable', 'array', 'max:10'],
            'currencies.*' => ['string', 'size:3'],
        ]);
        $store = $this->context($request)->store;
        $config = $store->tamaraConfig ?: new TamaraConfig(['store_id' => $store->id]);
        $config->mode = $input['environment'] === 'live' ? TamaraMode::Production : TamaraMode::Sandbox;
        foreach (['merchantToken' => 'api_token', 'notificationToken' => 'notification_token', 'publicKey' => 'public_key'] as $key => $column) {
            if (filled($input[$key] ?? null)) {
                $config->{$column} = $input[$key];
            }
        }
        $config->currency_allowlist = array_map('strtoupper', $input['currencies'] ?? ($config->currency_allowlist ?: []));
        $config->webhook_url = route('webhooks.tamara');
        $config->save();

        return response()->json($this->safe($config));
    }

    public function test(Request $request): JsonResponse
    {
        $config = $this->context($request)->store->tamaraConfig;
        abort_unless($config && filled($config->api_token), 422, 'Tamara credentials are not configured.');
        $currency = strtoupper((string) ($config->currency_allowlist[0] ?? 'SAR'));
        $this->client->request($config->store, 'GET', 'checkout/payment-types', [
            'country' => $this->countryForCurrency($currency),
            'currency' => $currency,
        ]);
        $config->update(['last_tested_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function countryForCurrency(string $currency): string
    {
        return match ($currency) {
            'AED' => 'AE',
            'KWD' => 'KW',
            'BHD' => 'BH',
            'QAR' => 'QA',
            'OMR' => 'OM',
            default => 'SA',
        };
    }

    public function enable(Request $request): JsonResponse
    {
        $config = $this->context($request)->store->tamaraConfig;
        abort_unless($config && filled($config->api_token) && filled($config->notification_token), 422, 'Test and configure credentials first.');
        $config->update(['enabled' => $request->boolean('enabled', true)]);

        return response()->json($this->safe($config));
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->context($request)->store->tamaraConfig()->delete();

        return response()->json(['connected' => false]);
    }

    private function context(Request $request): StoreContext
    {
        return $request->attributes->get(StoreContext::class);
    }

    private function safe(TamaraConfig $config): array
    {
        return [
            'connected' => true,
            'enabled' => $config->enabled,
            'environment' => $config->mode === TamaraMode::Production ? 'live' : 'sandbox',
            'merchantTokenConfigured' => filled($config->api_token),
            'notificationTokenConfigured' => filled($config->notification_token),
            'publicKeyConfigured' => filled($config->public_key),
            'currencies' => $config->currency_allowlist ?? [],
            'lastTestedAt' => $config->last_tested_at?->toIso8601String(),
        ];
    }
}
