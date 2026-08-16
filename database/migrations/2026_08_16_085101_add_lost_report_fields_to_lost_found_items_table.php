<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('registered_items', 'approved_at')) {
            Schema::table('registered_items', function (Blueprint $table) {
                $table
                    ->timestamp('approved_at')
                    ->nullable()
                    ->after('status');
            });
        }

        if (!Schema::hasColumn('registered_items', 'qr_expires_at')) {
            Schema::table('registered_items', function (Blueprint $table) {
                $table
                    ->timestamp('qr_expires_at')
                    ->nullable()
                    ->after('approved_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * Left empty so existing development databases
     * are not accidentally damaged by rollback.
     */
    public function down(): void
    {
        //
    }
};