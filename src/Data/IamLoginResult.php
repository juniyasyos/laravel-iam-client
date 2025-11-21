<?php

namespace Juniyasyos\IamClient\Data;

use Illuminate\Contracts\Auth\Authenticatable;

class IamLoginResult
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $payload,
        public readonly string $guard
    ) {
    }
}
