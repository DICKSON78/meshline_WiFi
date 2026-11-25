<?php

namespace App\Services\Payment;

/**
 * Stable scheduler surface mirroring services/paymentReconciler.js.  The actual
 * implementation lives in PaymentFulfillment so all money movement shares one
 * transaction/idempotency model.
 */
class PaymentReconciler
{
    public static function reconcilePayments(): int
    {
        return PaymentFulfillment::reconcile();
    }

    public static function reconcile(): int
    {
        return PaymentFulfillment::reconcile();
    }

    public static function processAndActivate(string $orderReference): array
    {
        return PaymentFulfillment::processAndActivate($orderReference);
    }

    public static function processPayouts(): int
    {
        return PaymentFulfillment::processPayouts();
    }

    public static function notifyPaymentFailure(string $orderReference, ?string $reason = null): void
    {
        PaymentFulfillment::notifyPaymentFailure($orderReference, $reason);
    }
}
