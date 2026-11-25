<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Hotbando
{
    public const MIB = 1048576;

    public static function normalizePhone(?string $phone): string
    {
        $phone = preg_replace('/[\s\-()]+/', '', (string) $phone) ?? '';
        $phone = preg_replace('/^\+?255/', '0', $phone);
        if (preg_match('/^[67]\d{8}$/', $phone)) {
            $phone = '0'.$phone;
        }

        return $phone;
    }

    public static function intlPhone(?string $phone): ?string
    {
        $phone = self::normalizePhone($phone);

        return preg_match('/^0\d{9}$/', $phone) ? '255'.substr($phone, 1) : null;
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        try {
            $row = DB::table('system_settings')->where('setting_key', $key)->first(['setting_value']);
        } catch (\Throwable $e) {
            return $default;
        }
        if (! $row) {
            return $default;
        }
        $value = $row->setting_value;

        return match (strtolower((string) ($row->setting_type ?? ''))) {
            'number' => is_numeric($value) ? $value + 0 : $default,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $value, true) ?? $default,
            default => $value,
        };
    }

    public static function decode(?string $json, mixed $default = []): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $decoded = json_decode($json, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    public static function activity(?int $userId, string $action, string $entityType = '', ?int $entityId = null, ?string $description = null, ?string $ip = null, ?string $agent = null, array $metadata = []): void
    {
        try {
            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'ip_address' => $ip,
                'user_agent' => $agent ? substr($agent, 0, 500) : null,
                'metadata' => $metadata ? self::encode($metadata) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function notify(?int $userId, string $type, string $title, string $message, ?string $actionUrl = null, ?string $actionText = null): void
    {
        try {
            DB::table('notifications')->insert([
                'user_id' => $userId,
                'notification_type' => $type,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'action_text' => $actionText,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
