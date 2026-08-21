<?php

namespace App\Exceptions\Billing;

final class BillingNotFoundException extends BillingException
{
    public function __construct(string $message = 'El documento no existe en el servicio de facturación.')
    {
        parent::__construct($message, 404);
    }
}
