<?php

use App\Support\SqlImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/** Migration A: import the 36-table legacy schema verbatim. */
return new class extends Migration
{
    public function up(): void
    {
        if (! SqlImporter::isMysql()) {
            Log::notice('[LegacySchema] Skipping MySQL schema.sql import on non-MySQL connection.');

            return;
        }

        SqlImporter::import(base_path('../database/schema.sql'));
    }

    public function down(): void
    {
        // Legacy DDL is shared with the Node deployment; it is not reversible.
    }
};
