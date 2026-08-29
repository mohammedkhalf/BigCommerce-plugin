<?php

namespace App\Support;

use App\Models\Store;
use App\Models\StoreUser;

final readonly class StoreContext
{
    public function __construct(
        public Store $store,
        public StoreUser $user,
    ) {}
}
