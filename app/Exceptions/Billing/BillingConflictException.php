<?php

namespace App\Exceptions\Billing;

final class BillingConflictException extends BillingException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }
}
