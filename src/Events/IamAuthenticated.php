<?php

namespace Juniyasyos\IamClient\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class IamAuthenticated
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly Authenticatable $user,
        public readonly array $payload,
        public readonly string $guard
    ) {
    }
}
