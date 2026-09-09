<?php

namespace App\Support;

use App\Models\Store;

final class BigCommerceScopes
{
    /**
     * @return array<string, list<string>>
     */
    public static function missingForCheckout(Store $store): array
    {
        return self::missingAnyOf($store, config('bigcommerce.oauth_scopes.checkout', []));
    }

    /**
     * Each group is satisfied when the store has at least one listed scope.
     *
     * @param  array<string, list<string>>  $groups
     * @return array<string, list<string>>
     */
    public static function missingAnyOf(Store $store, array $groups): array
    {
        $granted = $store->scopes ?? [];
        $missing = [];

        foreach ($groups as $label => $alternatives) {
            if (! array_intersect($alternatives, $granted)) {
                $missing[$label] = $alternatives;
            }
        }

        return $missing;
    }
}
