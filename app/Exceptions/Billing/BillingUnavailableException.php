<?php

namespace App\Exceptions\Billing;

final class BillingUnavailableException extends BillingException
{
    public function __construct(string $message = 'El servicio de facturación no está disponible temporalmente.')
    {
        parent::__construct($message, 503);
    }
}
