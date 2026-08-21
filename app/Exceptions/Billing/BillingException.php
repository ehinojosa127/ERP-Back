<?php

namespace App\Exceptions\Billing;

use RuntimeException;

abstract class BillingException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function errors(): array
    {
        return $this->errors;
    }
}
