<?php

namespace App\Services\Payment;

/** Scheduler surface for services/withdrawalPayouts.js. */
class WithdrawalPayouts
{
    public static function processWithdrawalPayouts(): int
    {
        return PaymentFulfillment::processPayouts();
    }

    public static function normalizePhone(?string $phone): ?string
    {
        return PaymentFulfillment::normalizePhone($phone);
    }
}
