<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Legacy migration.
     *
     * The previous student-created lost-report workflow is no longer used.
     * The current Lost & Found structure is defined by the main
     * lost_found_items migration and later compatibility migrations.
     */
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};