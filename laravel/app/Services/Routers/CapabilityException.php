<?php

namespace App\Services\Routers;

class CapabilityException extends \RuntimeException
{
    public function __construct(public readonly string $capability, string $driver = 'router')
    {
        parent::__construct("The {$driver} driver does not support '{$capability}'.");
    }
}
