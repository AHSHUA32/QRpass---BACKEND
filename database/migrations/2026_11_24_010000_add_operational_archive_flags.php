<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['scan_logs', 'security_incidents', 'registered_items'] as $name) {
            if (!Schema::hasColumn($name, 'operational_archived_at')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->timestamp('operational_archived_at')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        // Retain archive state and management evidence on rollback.
    }
};
