<?php

namespace App\Services\Payment;

use App\Support\Meshline;
use Illuminate\Support\Facades\DB;

/** Port of utils/packageEntitlement.js: stacking time and data limits under a row lock. */
class PackageEntitlement
{
    public static function extendUserPackage(mixed $executor, int $userId, array $pkg): array
    {
        $db = $executor ?? DB::getFacadeRoot();
        $user = $db->table('users')->where('id', $userId)->lockForUpdate()->first();
        if (! $user) {
            throw new \RuntimeException("Customer {$userId} was not found for package activation");
        }
        $now = now();
        $usageUntilRaw = $user->usage_until ? date_create_immutable((string) $user->usage_until) : null;
        $currentlyPaid = $usageUntilRaw !== null && $usageUntilRaw > $now;
        $usageStart = ($currentlyPaid && $user->usage_start) ? $user->usage_start : $now;
        $untilMillis = ($currentlyPaid ? $usageUntilRaw->getTimestamp() : $now->getTimestamp())
            + max(0, (int) ($pkg['duration_hours'] ?? 0)) * 3600;
        $usageUntil = (new \DateTimeImmutable())->setTimestamp($untilMillis);
        $packageLimit = max(0, (float) ($pkg['data_limit_mb'] ?? 0)) * Meshline::MIB;

        $paidDataLimit = $packageLimit;
        $paidBytesRemaining = $packageLimit;
        if ($packageLimit > 0 && $currentlyPaid && (float) ($user->paid_data_limit_bytes ?? 0) > 0) {
            $paidDataLimit += (float) $user->paid_data_limit_bytes;
            $paidBytesRemaining += (float) ($user->paid_bytes_remaining ?? 0);
        }

        $db->table('users')->where('id', $userId)->update([
            'package' => $pkg['name'] ?? null,
            'usage_start' => $usageStart,
            'usage_until' => $usageUntil,
            'paid_data_limit_bytes' => $paidDataLimit,
            'paid_bytes_remaining' => $paidBytesRemaining,
            'updated_at' => $now,
        ]);

        return [
            'usageStart' => $usageStart,
            'usageUntil' => $usageUntil,
            'paidDataLimit' => $paidDataLimit,
            'paidBytesRemaining' => $paidBytesRemaining,
        ];
    }
}
