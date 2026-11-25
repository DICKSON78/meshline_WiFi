<?php

use App\Support\SqlImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Migration C: everything added by the Node JS migrations that is not already
 * present in the SQL dumps. Every change is guarded so it can run against both
 * a fresh Laravel database and an existing legacy HotBando database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenUserRoles();
        $this->addRouterColumns();
        $this->addAdColumns();
        $this->addResellerColumns();
        $this->addWalletColumns();
        $this->createMissingLegacyTables();
        $this->createLaravelInfrastructureTables();
    }

    public function down(): void
    {
        // Additive compatibility migration; no destructive rollback.
    }

    /** users.role must also accept investor and reseller. */
    private function widenUserRoles(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        $roles = [
            'customer', 'admin', 'sponsor', 'bank_partner',
            'franchise_owner', 'super_admin', 'reseller', 'investor',
        ];
        $this->extendEnum('users', 'role', $roles, 'customer', ['reseller', 'investor']);
    }

    /** Setup-wizard, hotspot and Omada driver settings on mikrotiks. */
    private function addRouterColumns(): void
    {
        if (! Schema::hasTable('mikrotiks')) {
            return;
        }

        $columns = [
            'setup_error' => static fn (Blueprint $t) => $t->text('setup_error')->nullable(),
            'setup_config' => static fn (Blueprint $t) => $t->json('setup_config')->nullable(),
            'hotspot_server_name' => static fn (Blueprint $t) => $t->string('hotspot_server_name', 50)->default('hotbando-server'),
            'hotspot_ip' => static fn (Blueprint $t) => $t->string('hotspot_ip', 50)->default('10.5.50.1'),
            'hotspot_interface' => static fn (Blueprint $t) => $t->string('hotspot_interface', 50)->default('wlan1'),
            'radius_secret' => static fn (Blueprint $t) => $t->string('radius_secret', 255)->default('CHANGE-ME-IN-PRODUCTION'),
            'driver' => static fn (Blueprint $t) => $t->string('driver', 20)->default('mikrotik'),
            'omada_mode' => static fn (Blueprint $t) => $t->enum('omada_mode', ['onprem', 'cloud'])->nullable()->default('onprem'),
            'omada_controller_url' => static fn (Blueprint $t) => $t->string('omada_controller_url', 255)->nullable(),
            'omada_site_id' => static fn (Blueprint $t) => $t->string('omada_site_id', 100)->nullable(),
            'omada_client_id' => static fn (Blueprint $t) => $t->string('omada_client_id', 255)->nullable(),
            'omada_client_secret' => static fn (Blueprint $t) => $t->string('omada_client_secret', 255)->nullable(),
            'omada_username' => static fn (Blueprint $t) => $t->string('omada_username', 100)->nullable(),
            'omada_password' => static fn (Blueprint $t) => $t->string('omada_password', 255)->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            $this->addColumnIfMissing('mikrotiks', $column, $definition);
        }
    }

    /** Ad moderation: categories, pricing, duration options and soft deletes. */
    private function addAdColumns(): void
    {
        $this->createIfMissing('ad_categories', static function (Blueprint $t): void {
            $t->integer('id')->autoIncrement();
            $t->string('name', 100)->unique();
            $t->text('description')->nullable();
            $t->string('color', 7)->default('#FF7A30');
            $t->timestamp('created_at')->useCurrent();
        });

        if (! Schema::hasTable('ads')) {
            return;
        }

        $columns = [
            'campaign_id' => static fn (Blueprint $t) => $t->integer('campaign_id')->nullable(),
            'duration_options' => static fn (Blueprint $t) => $t->string('duration_options', 50)->default('1d,5d,1w,2w,1m'),
            'price_shilling' => static fn (Blueprint $t) => $t->decimal('price_shilling', 10, 2)->default(0),
            'category_id' => static fn (Blueprint $t) => $t->integer('category_id')->nullable(),
            'expires_at' => static fn (Blueprint $t) => $t->dateTime('expires_at')->nullable(),
            'deleted_at' => static fn (Blueprint $t) => $t->dateTime('deleted_at')->nullable(),
            'deleted_by' => static fn (Blueprint $t) => $t->integer('deleted_by')->nullable(),
            'delete_reason' => static fn (Blueprint $t) => $t->string('delete_reason', 500)->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            $this->addColumnIfMissing('ads', $column, $definition);
        }

        $this->addIndexIfMissing('ads', 'idx_ads_campaign', ['campaign_id']);
        $this->addForeignKeyIfMissing(
            'ads',
            'category_id',
            'ad_categories',
            'fk_ads_category'
        );

        // Legacy platform-owned ads have no sponsor.
        if (SqlImporter::isMysql()) {
            $row = DB::selectOne(
                'SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['ads', 'sponsor_id']
            );
            if ($row && ($row->nullable ?? 'YES') === 'NO') {
                Schema::table('ads', static function (Blueprint $t): void {
                    $t->integer('sponsor_id')->nullable()->change();
                });
            }
        }
    }

    /** Reseller profit, router/location links and payout-aware withdrawals. */
    private function addResellerColumns(): void
    {
        $this->addColumnIfMissing('vouchers', 'reseller_profit', static fn (Blueprint $t) => $t->tinyInteger('reseller_profit')->default(0));

        if (Schema::hasTable('resellers')) {
            $this->addColumnIfMissing('resellers', 'router_id', static fn (Blueprint $t) => $t->integer('router_id')->nullable());
            $this->addColumnIfMissing('resellers', 'location_id', static fn (Blueprint $t) => $t->integer('location_id')->nullable());
        }

        if (! Schema::hasTable('reseller_withdrawals')) {
            return;
        }

        $statuses = ['pending', 'approved', 'processed', 'rejected', 'sending', 'sent', 'failed'];
        $this->extendEnum('reseller_withdrawals', 'status', $statuses, 'pending', ['sending', 'processed', 'failed']);

        $columns = [
            'fee' => static fn (Blueprint $t) => $t->decimal('fee', 10, 2)->nullable(),
            'payout_amount' => static fn (Blueprint $t) => $t->decimal('payout_amount', 10, 2)->nullable(),
            'order_reference' => static fn (Blueprint $t) => $t->string('order_reference', 40)->nullable(),
            'provider_ref' => static fn (Blueprint $t) => $t->string('provider_ref', 64)->nullable(),
            'attempts' => static fn (Blueprint $t) => $t->integer('attempts')->default(0),
            'last_error' => static fn (Blueprint $t) => $t->string('last_error', 255)->nullable(),
            'sent_at' => static fn (Blueprint $t) => $t->dateTime('sent_at')->nullable(),
            'requested_at' => static fn (Blueprint $t) => $t->dateTime('requested_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            $this->addColumnIfMissing('reseller_withdrawals', $column, $definition);
        }
    }

    private function addWalletColumns(): void
    {
        $this->addColumnIfMissing('wallets', 'sponsor_id', static function (Blueprint $t): void {
            $t->integer('sponsor_id')->nullable()->index('idx_sponsor_id');
        });
    }

    /** Tables introduced by JS migrations (or only present in SQL dumps). */
    private function createMissingLegacyTables(): void
    {
        $this->createIfMissing('pending_package_grants', static function (Blueprint $t): void {
            $t->integer('id')->autoIncrement();
            $t->integer('user_id');
            $t->integer('package_id');
            $t->string('order_reference', 64)->unique();
            $t->string('purchased_by_phone', 20)->nullable();
            $t->string('status', 10)->default('pending');
            $t->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $t->dateTime('claimed_at')->nullable();
            $t->index(['user_id', 'status'], 'idx_pending_grants_user');
        });

        $this->createIfMissing('hotspot_sessions', static function (Blueprint $t): void {
            $t->increments('id');
            $t->integer('user_id');
            $t->string('router_id', 50);
            $t->string('mac_address', 17);
            $t->timestamp('session_started_at')->useCurrent();
            $t->timestamp('last_activity_at')->useCurrent()->useCurrentOnUpdate();
            $t->unique(['user_id', 'router_id', 'mac_address'], 'unique_session');
            $t->index(['router_id', 'last_activity_at'], 'idx_router_time');
            $t->index('user_id', 'idx_user_id');
        });

        $userDevicesUserIndex = $this->indexName('idx_user_id', 'idx_user_devices_user');
        $this->createIfMissing('user_devices', static function (Blueprint $t) use ($userDevicesUserIndex): void {
            $t->increments('id');
            $t->integer('user_id');
            $t->string('mac_address', 17);
            $t->timestamp('first_seen')->useCurrent();
            $t->timestamp('last_seen')->useCurrent()->useCurrentOnUpdate();
            $t->unique(['user_id', 'mac_address'], 'uniq_user_device');
            $t->index('user_id', $userDevicesUserIndex);
            $t->index('mac_address', 'idx_mac_address');
        });

        $this->createIfMissing('payment_ledger', static function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('business_id')->nullable();
            $t->unsignedBigInteger('partner_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('order_reference', 128)->nullable();
            $t->unsignedBigInteger('payment_request_id')->nullable();
            $t->unsignedBigInteger('clickpesa_transaction_id')->nullable();
            $t->enum('type', ['payment', 'payout', 'refund', 'reversal']);
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('currency', 8)->default('TZS');
            $t->string('status', 32)->default('pending');
            $t->string('provider', 32)->default('clickpesa');
            $t->string('provider_ref', 128)->nullable();
            $t->text('description')->nullable();
            $t->json('metadata')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index('customer_id', 'idx_ledger_customer');
            $t->index('order_reference', 'idx_ledger_order');
            $t->index('created_at', 'idx_ledger_created');
        });

        // Tables normally installed by the sorted SQL migrations. Recreated
        // here only when those files were not applied (e.g. fresh Laravel DB).
        $assignmentRouterIndex = $this->indexName('idx_router', 'idx_assignment_router');
        $assignmentResellerIndex = $this->indexName('idx_reseller', 'idx_assignment_reseller');
        $this->createIfMissing('router_assignments', static function (Blueprint $t) use ($assignmentRouterIndex, $assignmentResellerIndex): void {
            $t->increments('id');
            $t->string('router_id', 100);
            $t->integer('reseller_user_id');
            $t->string('display_name', 120);
            $t->decimal('commission_percent', 5, 2)->default(20.00);
            $t->dateTime('assigned_at')->useCurrent();
            $t->dateTime('unassigned_at')->nullable();
            $t->integer('assigned_by')->nullable();
            $t->integer('unassigned_by')->nullable();
            $t->string('active_key')->storedAs('CASE WHEN unassigned_at IS NULL THEN router_id END');
            $t->unique('active_key', 'uq_active_router');
            $t->index('router_id', $assignmentRouterIndex);
            $t->index('reseller_user_id', $assignmentResellerIndex);
        });

        $saleRouterIndex = $this->indexName('idx_router', 'idx_router_sale_router');
        $saleResellerIndex = $this->indexName('idx_reseller', 'idx_router_sale_reseller');
        $this->createIfMissing('router_sales', static function (Blueprint $t) use ($saleRouterIndex, $saleResellerIndex): void {
            $t->bigIncrements('id');
            $t->string('order_reference', 64)->unique();
            $t->string('router_id', 100);
            $t->unsignedInteger('assignment_id')->nullable();
            $t->integer('reseller_user_id')->nullable();
            $t->integer('customer_id')->nullable();
            $t->string('purpose', 50);
            $t->string('currency', 10)->default('TZS');
            $t->decimal('gross_amount', 12, 2);
            $t->decimal('hq_percent', 5, 2);
            $t->decimal('hq_amount', 12, 2);
            $t->decimal('reseller_amount', 12, 2);
            $t->enum('status', ['credited', 'reversed'])->default('credited');
            $t->dateTime('created_at')->useCurrent();
            $t->decimal('repayment_amount', 12, 2)->default(0);
            $t->unsignedInteger('financing_id')->nullable();
            $t->index('router_id', $saleRouterIndex);
            $t->index('reseller_user_id', $saleResellerIndex);
            $t->index('created_at', 'idx_created');
        });

        $this->createIfMissing('equipment_partners', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 150);
            $t->string('contact_person', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 190)->nullable();
            $t->text('notes')->nullable();
            $t->tinyInteger('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
        });

        $equipmentPlanPartnerIndex = $this->indexName('idx_partner', 'idx_equipment_plan_partner');
        $this->createIfMissing('equipment_plans', static function (Blueprint $t) use ($equipmentPlanPartnerIndex): void {
            $t->increments('id');
            $t->unsignedInteger('partner_id')->nullable();
            $t->string('name', 150);
            $t->enum('plan_type', ['hq_supplied', 'outright', 'financed']);
            $t->text('equipment_list')->nullable();
            $t->decimal('total_price', 12, 2)->default(0);
            $t->decimal('deposit', 12, 2)->default(0);
            $t->decimal('financed_amount', 12, 2)->default(0);
            $t->decimal('repayment_percent', 5, 2)->default(0);
            $t->decimal('maintenance_percent', 5, 2)->default(10);
            $t->tinyInteger('is_active')->default(1);
            $t->dateTime('created_at')->useCurrent();
            $t->index('partner_id', $equipmentPlanPartnerIndex);
        });

        $this->createIfMissing('reseller_applications', static function (Blueprint $t): void {
            $t->increments('id');
            $t->string('application_ref', 20)->unique();
            $t->string('name', 150);
            $t->string('business_name', 150)->nullable();
            $t->string('phone', 20);
            $t->string('email', 190)->nullable();
            $t->string('password_hash', 255);
            $t->string('region', 80);
            $t->string('district', 80);
            $t->string('ward', 80)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('landmark', 255)->nullable();
            $t->string('business_type', 80)->nullable();
            $t->integer('expected_customers')->nullable();
            $t->unsignedInteger('plan_id')->nullable();
            $t->text('notes')->nullable();
            $t->enum('status', ['new', 'reviewing', 'approved', 'scheduled', 'installed', 'rejected'])->default('new');
            $t->string('rejection_reason', 500)->nullable();
            $t->text('admin_notes')->nullable();
            $t->integer('user_id')->nullable();
            $t->date('install_date')->nullable();
            $t->string('install_time', 20)->nullable();
            $t->string('technician', 100)->nullable();
            $t->string('install_notes', 500)->nullable();
            $t->string('created_ip', 64)->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index('phone', 'idx_phone');
            $t->index('status', 'idx_status');
        });

        $financingResellerIndex = $this->indexName('idx_reseller', 'idx_financing_reseller');
        $financingPartnerIndex = $this->indexName('idx_partner', 'idx_financing_partner');
        $this->createIfMissing('reseller_financings', static function (Blueprint $t) use ($financingResellerIndex, $financingPartnerIndex): void {
            $t->increments('id');
            $t->unsignedInteger('application_id')->nullable();
            $t->integer('reseller_user_id');
            $t->unsignedInteger('plan_id')->nullable();
            $t->unsignedInteger('partner_id')->nullable();
            $t->string('router_id', 100)->nullable();
            $t->decimal('principal', 12, 2);
            $t->decimal('balance', 12, 2);
            $t->decimal('repayment_percent', 5, 2);
            $t->decimal('maintenance_percent', 5, 2)->default(10);
            $t->enum('status', ['pending', 'active', 'completed', 'cancelled'])->default('pending');
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->dateTime('ownership_transferred_at')->nullable();
            $t->dateTime('created_at')->useCurrent();
            $t->index(['reseller_user_id', 'status'], $financingResellerIndex);
            $t->index('partner_id', $financingPartnerIndex);
        });

        $this->createIfMissing('financing_repayments', static function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedInteger('financing_id');
            $t->string('order_reference', 64)->unique();
            $t->decimal('amount', 12, 2);
            $t->decimal('balance_after', 12, 2);
            $t->dateTime('created_at')->useCurrent();
            $t->index('financing_id', 'idx_fin');
        });

        $payoutPartnerIndex = $this->indexName('idx_partner', 'idx_payout_partner');
        $this->createIfMissing('partner_payouts', static function (Blueprint $t) use ($payoutPartnerIndex): void {
            $t->increments('id');
            $t->unsignedInteger('partner_id');
            $t->decimal('amount', 12, 2);
            $t->string('reference', 100)->nullable();
            $t->string('note', 500)->nullable();
            $t->dateTime('paid_at')->useCurrent();
            $t->index('partner_id', $payoutPartnerIndex);
        });

        $applicationStatusIndex = $this->indexName('idx_status', 'idx_applications_status');
        $this->createIfMissing('applications', static function (Blueprint $t) use ($applicationStatusIndex): void {
            $t->integer('id')->autoIncrement();
            $t->enum('role', ['franchise_owner', 'sponsor', 'bank_partner', 'investor', 'reseller']);
            $t->string('name', 255);
            $t->string('email', 255)->nullable();
            $t->string('phone', 20);
            $t->string('password_hash', 255);
            $t->string('company', 255)->nullable();
            $t->string('location', 255)->nullable();
            $t->string('business_type', 100)->nullable();
            $t->text('notes')->nullable();
            $t->decimal('fee_amount', 10, 2)->default(0);
            $t->enum('status', [
                'pending', 'submitted', 'payment_pending',
                'payment_made', 'payment_failed', 'approved', 'rejected',
            ])->default('pending');
            $t->string('order_reference', 255)->nullable();
            $t->text('clickpesa_response')->nullable();
            $t->integer('user_id')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $t->index('status', $applicationStatusIndex);
            $t->index('role', 'idx_role');
            $t->index('order_reference', 'idx_order_reference');
        });
    }

    /** Laravel-owned tables that we own now that the default migrations are gone. */
    private function createLaravelInfrastructureTables(): void
    {
        $this->createIfMissing('laravel_sessions', static function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        $this->createIfMissing('cache', static function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration')->index();
        });

        $this->createIfMissing('cache_locks', static function (Blueprint $t): void {
            $t->string('key')->primary();
            $t->string('owner');
            $t->integer('expiration')->index();
        });

        $this->createIfMissing('jobs', static function (Blueprint $t): void {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        $this->createIfMissing('job_batches', static function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('name');
            $t->integer('total_jobs');
            $t->integer('pending_jobs');
            $t->integer('failed_jobs');
            $t->longText('failed_job_ids');
            $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable();
            $t->integer('created_at');
            $t->integer('finished_at')->nullable();
        });

        $this->createIfMissing('failed_jobs', static function (Blueprint $t): void {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    /** SQLite index names are global, MySQL's are per-table. */
    private function indexName(string $mysqlName, string $sqliteName): string
    {
        return SqlImporter::isMysql() ? $mysqlName : $sqliteName;
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

    private function createIfMissing(string $table, callable $definition): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, static function (Blueprint $blueprint) use ($definition): void {
            $definition($blueprint);
        });
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, static function (Blueprint $t) use ($index, $columns): void {
                $t->index($columns, $index);
            });
        } catch (Throwable $e) {
            Log::notice('[LegacySchema] Could not add index '.$table.'.'.$index.': '.$e->getMessage());
        }
    }

    private function addForeignKeyIfMissing(string $table, string $column, string $referencedTable, string $name): void
    {
        if (! SqlImporter::isMysql() || ! Schema::hasTable($table) || ! Schema::hasTable($referencedTable)) {
            return;
        }

        $existing = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME = ?',
            [$table, $column, $referencedTable]
        );
        if ((int) ($existing->c ?? 0) > 0) {
            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencedTable}`(`id`)");
        } catch (Throwable $e) {
            Log::notice('[LegacySchema] Could not add foreign key '.$name.': '.$e->getMessage());
        }
    }

    private function extendEnum(string $table, string $column, array $values, string $default, array $requiredValues): void
    {
        foreach ($requiredValues as $value) {
            if (! $this->enumContains($table, $column, $value)) {
                Schema::table($table, static function (Blueprint $t) use ($column, $values, $default): void {
                    $t->enum($column, $values)->default($default)->change();
                });

                return;
            }
        }
    }

    private function enumContains(string $table, string $column, string $value): bool
    {
        if (! SqlImporter::isMysql()) {
            return true;
        }

        $row = DB::selectOne(
            'SELECT COLUMN_TYPE AS `type` FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $row !== null && str_contains(strtolower((string) $row->type), "'".strtolower($value)."'");
    }
};
