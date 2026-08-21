<?php

namespace App\Exceptions\Billing;

final class BillingValidationException extends BillingException
{
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message, 422, $errors);
    }
}
