<?php

namespace App\Services\Payment;

/** Provider error carrying the HTTP status so callers can retry transient failures. */
class ClickPesaException extends \RuntimeException
{
    public function __construct(string $message, public int $statusCode = 0, public mixed $payload = null)
    {
        parent::__construct($message);
    }
}
