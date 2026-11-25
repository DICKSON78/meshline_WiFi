<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Idempotent defaults used by the legacy Node API. */
class LegacySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedPackages();
        $this->seedSystemSettings();
    }

    private function seedPackages(): void
    {
        if (! Schema::hasTable('packages')) {
            Log::notice('[LegacySeeder] packages table missing; skipping package defaults.');

            return;
        }

        $packages = [
            [
                'id' => 1,
                'name' => 'MASAA 6',
                'description' => '6 hours of internet access',
                'duration_hours' => 6,
                'price' => 500.00,
                'currency' => 'TZS',
                'is_active' => 1,
                'sort_order' => 1,
            ],
            [
                'id' => 2,
                'name' => 'MASAA 24',
                'description' => '24 hours (1 day) of internet access',
                'duration_hours' => 24,
                'price' => 1000.00,
                'currency' => 'TZS',
                'is_active' => 1,
                'sort_order' => 2,
            ],
            [
                'id' => 3,
                'name' => 'WIKI 1',
                'description' => '1 week of internet access',
                'duration_hours' => 168,
                'price' => 6000.00,
                'currency' => 'TZS',
                'is_active' => 1,
                'sort_order' => 3,
            ],
            [
                'id' => 4,
                'name' => 'MWEZI 1',
                'description' => '1 month of internet access',
                'duration_hours' => 720,
                'price' => 40000.00,
                'currency' => 'TZS',
                'is_active' => 1,
                'sort_order' => 4,
            ],
        ];

        foreach ($packages as $package) {
            $existing = DB::table('packages')
                ->where('id', $package['id'])
                ->orWhere('name', $package['name'])
                ->first(['id']);

            if ($existing) {
                DB::table('packages')->where('id', $existing->id)->update($package);
            } else {
                DB::table('packages')->insert($package);
            }
        }
    }

    private function seedSystemSettings(): void
    {
        if (! Schema::hasTable('system_settings')) {
            Log::notice('[LegacySeeder] system_settings table missing; skipping defaults.');

            return;
        }

        $settings = [
            [
                'setting_key' => 'daily_ad_limit',
                'setting_value' => '8',
                'setting_type' => 'number',
                'description' => 'Maximum ads a user can watch per day',
                'is_public' => 1,
            ],
            [
                'setting_key' => 'ad_watch_reward_mb',
                'setting_value' => '15',
                'setting_type' => 'number',
                'description' => 'Data reward for watching one ad (MB)',
                'is_public' => 1,
            ],
            [
                'setting_key' => 'min_ad_watch_percentage',
                'setting_value' => '80',
                'setting_type' => 'number',
                'description' => 'Minimum percentage of ad to watch to get reward',
                'is_public' => 0,
            ],
            [
                'setting_key' => 'max_daily_ad_reward_bytes',
                'setting_value' => '524288000',
                'setting_type' => 'number',
                'description' => 'Maximum daily data reward from watched ads in bytes (500MB)',
                'is_public' => 0,
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['setting_key' => $setting['setting_key']],
                $setting
            );
        }
    }
}
