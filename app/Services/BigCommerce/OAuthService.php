<?php

namespace App\Services\BigCommerce;

use App\Jobs\ProvisionStoreAfterInstall;
use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class OAuthService
{
    public function __construct(
        private BigCommerceOAuthClient $client,
        private BigCommerceJwtVerifier $jwtVerifier,
    ) {}

    /**
     * @return array{store: Store, user: StoreUser}
     */
    public function install(string $code, string $context, string $scope): array
    {
        $storeHash = $this->storeHash($context);
        $payload = $this->client->exchange($code, $context, $scope);

        if (($payload['context'] ?? null) !== $context || empty($payload['access_token'])) {
            throw ValidationException::withMessages(['context' => 'The OAuth response context is invalid.']);
        }

        $ownerId = filter_var($payload['owner']['id'] ?? null, FILTER_VALIDATE_INT);
        $user = $this->validatedUser(
            $payload['user'] ?? null,
            $ownerId !== false && (int) $ownerId === (int) ($payload['user']['id'] ?? 0),
        );

        $session = DB::transaction(function () use ($storeHash, $payload, $scope, $user): array {
            $store = Store::query()->updateOrCreate(
                ['store_hash' => $storeHash],
                [
                    'access_token' => $payload['access_token'],
                    'account_uuid' => $payload['account_uuid'] ?? null,
                    'scopes' => preg_split('/\s+/', trim((string) ($payload['scope'] ?? $scope)), -1, PREG_SPLIT_NO_EMPTY),
                    'installed_at' => now(),
                    'uninstalled_at' => null,
                ],
            );

            return ['store' => $store, 'user' => $this->upsertUser($store, $user)];
        });

        ProvisionStoreAfterInstall::dispatch($session['store']->id)->afterCommit();

        return $session;
    }

    /**
     * @return array{store: Store, user: StoreUser}
     */
    public function load(string $signedPayload): array
    {
        $claims = $this->jwtVerifier->verify($signedPayload);
        $store = Store::query()
            ->where('store_hash', $this->storeHash((string) ($claims['sub'] ?? '')))
            ->whereNull('uninstalled_at')
            ->firstOrFail();

        return ['store' => $store, 'user' => $this->upsertUser($store, $this->validatedUser($claims['user'] ?? null))];
    }

    public function uninstall(string $signedPayload): void
    {
        $claims = $this->jwtVerifier->verify($signedPayload);

        Store::query()
            ->where('store_hash', $this->storeHash((string) ($claims['sub'] ?? '')))
            ->update(['access_token' => null, 'uninstalled_at' => now()]);
    }

    public function removeUser(string $signedPayload): void
    {
        $claims = $this->jwtVerifier->verify($signedPayload);
        $user = $this->validatedUser($claims['user'] ?? null);
        $store = Store::query()
            ->where('store_hash', $this->storeHash((string) ($claims['sub'] ?? '')))
            ->firstOrFail();

        $store->users()
            ->where('bigcommerce_user_id', $user['id'])
            ->update(['is_active' => false]);
    }

    private function storeHash(string $context): string
    {
        if (! preg_match('/^stores\/([a-z0-9]+)$/i', $context, $matches)) {
            throw ValidationException::withMessages(['context' => 'Invalid BigCommerce store context.']);
        }

        return $matches[1];
    }

    /**
     * @return array{id: int, email: string, name: ?string, locale: ?string, is_owner: bool}
     */
    private function validatedUser(mixed $user, ?bool $isOwner = null): array
    {
        if (! is_array($user) || ! filter_var($user['email'] ?? null, FILTER_VALIDATE_EMAIL)
            || filter_var($user['id'] ?? null, FILTER_VALIDATE_INT) === false) {
            throw new AuthenticationException('Invalid BigCommerce user claims.');
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => isset($user['name']) ? (string) $user['name'] : null,
            'locale' => isset($user['locale']) ? (string) $user['locale'] : null,
            'is_owner' => $isOwner ?? (bool) ($user['is_owner'] ?? false),
        ];
    }

    /**
     * @param  array{id: int, email: string, name: ?string, locale: ?string, is_owner: bool}  $user
     */
    private function upsertUser(Store $store, array $user): StoreUser
    {
        return $store->users()->updateOrCreate(
            ['bigcommerce_user_id' => $user['id']],
            [
                'email' => $user['email'],
                'name' => $user['name'],
                'locale' => $user['locale'],
                'is_owner' => $user['is_owner'],
                'is_active' => true,
            ],
        );
    }
}
