<?php

namespace Juniyasyos\IamClient\Exceptions;

use RuntimeException;

class IamAuthenticationException extends RuntimeException
{
    /**
     * Optional error payload from IAM server.
     */
    public function __construct(string $message = 'Unable to authenticate with IAM', public readonly ?array $context = null)
    {
        parent::__construct($message);
    }
}
