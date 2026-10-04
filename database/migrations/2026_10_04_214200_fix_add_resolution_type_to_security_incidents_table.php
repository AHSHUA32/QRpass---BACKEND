<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_incidents', function (Blueprint $table) {
            if (!Schema::hasColumn('security_incidents', 'resolution_type')) {
                $table->string('resolution_type', 100)
                    ->nullable()
                    ->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('security_incidents', function (Blueprint $table) {
            if (Schema::hasColumn('security_incidents', 'resolution_type')) {
                $table->dropColumn('resolution_type');
            }
        });
    }
};