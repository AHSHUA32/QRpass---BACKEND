<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('security_incidents', 'item_name')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->string('item_name')->nullable();
            });
        }

        if (!Schema::hasColumn('security_incidents', 'owner_name')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->string('owner_name')->nullable();
            });
        }

        if (!Schema::hasColumn('security_incidents', 'owner_id_number')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->string('owner_id_number')->nullable();
            });
        }

        if (!Schema::hasColumn('security_incidents', 'carrier_name')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->string('carrier_name')->nullable();
            });
        }

        if (!Schema::hasColumn('security_incidents', 'carrier_id_number')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->string('carrier_id_number')->nullable();
            });
        }

        if (!Schema::hasColumn('security_incidents', 'direction')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                $table->string('direction')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty to protect existing
        // security_incidents columns and existing QRPass data.
    }
};