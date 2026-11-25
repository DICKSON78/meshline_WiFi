<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel-native replacement for database/migrations/001-business-fixes.sql.
 * That dump relies on stored procedures and PREPARE/EXECUTE, which are not
 * reliable through PDO on some MySQL distributions (notably XAMPP).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->add('payments', 'package_id', fn (Blueprint $t) => $t->integer('package_id')->nullable());
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'package_id')) {
            $this->index('payments', 'idx_package', ['package_id']);
        }
        $this->add('payments', 'activated', fn (Blueprint $t) => $t->tinyInteger('activated')->default(0));

        $this->add('user_connection_logs', 'bytes_charged', fn (Blueprint $t) => $t->tinyInteger('bytes_charged')->default(0));
        $this->modifyEnum('user_connection_logs', 'action',
            "'connect','disconnect','timeout','kicked','error','connect_failed'", 'connect_failed');
        $this->modifyEnum('payment_requests', 'payment_method',
            "'mpesa','tigopesa','airtelmoney','pesapal'", 'pesapal');

        $this->add('resellers', 'tier', fn (Blueprint $t) => $t->string('tier', 20)->default('bronze'));
        $this->add('resellers', 'commission_rate', fn (Blueprint $t) => $t->decimal(5, 2)->default(10.00));
        $this->add('resellers', 'total_vouchers_sold', fn (Blueprint $t) => $t->integer('total_vouchers_sold')->default(0));

        foreach ([
            'user_id' => fn (Blueprint $t) => $t->integer('user_id')->nullable(),
            'payment_method' => fn (Blueprint $t) => $t->string('payment_method', 50)->nullable()->default('mobile_money'),
            'payment_phone' => fn (Blueprint $t) => $t->string('payment_phone', 20)->nullable(),
            'processed_by' => fn (Blueprint $t) => $t->integer('processed_by')->nullable(),
            'notes' => fn (Blueprint $t) => $t->text('notes')->nullable(),
            'created_at' => fn (Blueprint $t) => $t->dateTime('created_at')->useCurrent(),
        ] as $column => $definition) {
            $this->add('reseller_withdrawals', $column, $definition);
        }

        if (Schema::hasTable('packages')) {
            DB::table('packages')->where('name', 'MWEEZI 1')->update(['name' => 'MWEZI 1']);
        }
        if (Schema::hasTable('users')) {
            foreach (['1' => 'MASAA 6', '2' => 'MASAA 24', '3' => 'WIKI 1', '4' => 'MWEZI 1'] as $id => $name) {
                DB::table('users')->where('package', $id)->update(['package' => $name]);
            }
        }
    }

    public function down(): void
    {
        // Legacy schema is shared with the reference deployment; not reversible.
    }

    private function add(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }
        Schema::table($table, static function (Blueprint $t) use ($definition): void {
            $definition($t);
        });
    }

    private function index(string $table, string $name, array $columns): void
    {
        $exists = DB::selectOne(
            'SELECT COUNT(*) as c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $name]
        );
        if ((int) ($exists->c ?? 0) === 0) {
            Schema::table($table, static fn (Blueprint $t) => $t->index($columns, $name));
        }
    }

    private function modifyEnum(string $table, string $column, string $values, string $needle): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if ($row && str_contains((string) $row->type, $needle)) {
            return;
        }
        DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` ENUM({$values}) NOT NULL");
    }
};
