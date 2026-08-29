<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\Encrypter;

final readonly class SecretEncrypter
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(?string $value): ?string
    {
        return $value === null ? null : $this->encrypter->encryptString($value);
    }

    public function decrypt(?string $value): ?string
    {
        return $value === null ? null : $this->encrypter->decryptString($value);
    }
}
