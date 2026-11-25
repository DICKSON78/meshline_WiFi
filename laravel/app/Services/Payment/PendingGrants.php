<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\DB;

/** Port of utils/pendingGrants.js — gifts activated on the recipient's first WiFi login. */
class PendingGrants
{
    /** @return string[] */
    public static function phoneVariants(?string $normalized255): array
    {
        $digits = preg_replace('/\D/', '', (string) $normalized255) ?? '';
        if (! str_starts_with($digits, '255') || strlen($digits) !== 12) {
            return [];
        }

        return [$digits, '+'.$digits, '0'.substr($digits, 3)];
    }

    public static function findCustomerByPhone(mixed $executor, ?string $normalized255): ?array
    {
        $variants = self::phoneVariants($normalized255);
        if ($variants === []) {
            return null;
        }
        $db = $executor ?? DB::getFacadeRoot();
        $row = $db->table('users')
            ->whereIn('phone_number', $variants)
            ->where('role', 'customer')
            ->where('is_active', 1)
            ->first(['id', 'name', 'phone_number']);

        return $row ? (array) $row : null;
    }

    public static function createGiftGrant(mixed $executor, int $recipientUserId, int $packageId, string $orderReference, ?string $buyerPhone): void
    {
        $db = $executor ?? DB::getFacadeRoot();
        $db->table('pending_package_grants')->insert([
            'user_id' => $recipientUserId,
            'package_id' => $packageId,
            'order_reference' => $orderReference,
            'purchased_by_phone' => $buyerPhone,
            'status' => 'pending',
            'created_at' => DB::raw('NOW()'),
        ]);
    }

    /** Claimed at WiFi connect time so the package countdown starts on first use. */
    public static function claimPendingGrants(mixed $executor, int $userId): int
    {
        $db = $executor ?? DB::getFacadeRoot();
        $grants = $db->table('pending_package_grants')
            ->where('user_id', $userId)->where('status', 'pending')->orderBy('id')->get();
        $claimed = 0;
        foreach ($grants as $grant) {
            $pack = $db->table('packages')->where('id', $grant->package_id)->first();
            if (! $pack) {
                continue;
            }
            PackageEntitlement::extendUserPackage($db, $userId, (array) $pack);
            $db->table('pending_package_grants')->where('id', $grant->id)
                ->where('status', 'pending')
                ->update(['status' => 'claimed', 'claimed_at' => DB::raw('NOW()')]);
            $claimed++;
        }

        return $claimed;
    }
}
