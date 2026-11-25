<?php

use App\Support\SqlImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Migration B: the remaining SQL migrations, then the add-columns.js changes. */
return new class extends Migration
{
    public function up(): void
    {
        if (SqlImporter::isMysql()) {
            $files = glob(base_path('../database/migrations/*.sql')) ?: [];
            // 001-business-fixes.sql uses stored procedures + PREPARE/EXECUTE,
            // which some MySQL builds (XAMPP) cannot run through PDO reliably.
            // Its changes are applied in 000004 with Schema builder instead.
            $files = array_values(array_filter($files, fn (string $f) => ! str_ends_with($f, '001-business-fixes.sql')));
            // router_sales must exist before onboarding_financing tries to ALTER it.
            usort($files, static function (string $a, string $b): int {
                $priority = static fn (string $f): int => match (basename($f)) {
                    '20251029_router_sales.sql' => 0,
                    '20251029_onboarding_financing.sql' => 1,
                    default => 2,
                };
                return $priority($a) <=> $priority($b) ?: strcmp($a, $b);
            });

            SqlImporter::importMany(array_merge($files, [
                base_path('../database/migrate-hq-management.sql'),
                base_path('../database/hotspot-sessions-schema.sql'),
                base_path('../database/applications-table.sql'),
                base_path('../database/migrate-pesapal.sql'),
                base_path('../database/migrate-setup-wizard.sql'),
                base_path('../database/migrate-router-location.sql'),
                base_path('../database/add-extra-data-column.sql'),
            ]));
        } else {
            Log::notice('[LegacySchema] Skipping MySQL incremental dump imports on non-MySQL connection.');
        }

        // database/add-columns.js, translated to the Schema builder.
        if (Schema::hasTable('mikrotiks')) {
            $this->addColumnIfMissing('mikrotiks', 'is_active', static fn (Blueprint $t) => $t->tinyInteger('is_active')->default(1));
            $this->addColumnIfMissing('mikrotiks', 'active_users', static fn (Blueprint $t) => $t->integer('active_users')->default(0));
            $this->addColumnIfMissing('mikrotiks', 'location_id', static fn (Blueprint $t) => $t->integer('location_id')->nullable());

            DB::table('mikrotiks')->whereNull('is_active')->update(['is_active' => 1]);
            DB::table('mikrotiks')->whereNull('active_users')->update(['active_users' => 0]);
        }

        if (Schema::hasTable('locations')) {
            $this->addColumnIfMissing('locations', 'name', static fn (Blueprint $t) => $t->string('name')->nullable());
            $this->addColumnIfMissing('locations', 'franchise_owner_id', static fn (Blueprint $t) => $t->integer('franchise_owner_id')->nullable());

            if (Schema::hasColumn('locations', 'location_name')) {
                DB::table('locations')
                    ->whereNull('name')
                    ->orWhere('name', '')
                    ->update(['name' => DB::raw('location_name')]);
            }
        }
    }

    public function down(): void
    {
        // Raw legacy DDL is shared with the Node deployment; not reversible.
    }

    private function addColumnIfMissing(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, static function (Blueprint $blueprint) use ($definition): void {
            $definition($blueprint);
        });
    }
};
