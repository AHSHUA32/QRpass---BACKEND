<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('registered_items')) {
            throw new \RuntimeException('registered_items table missing; check database connection.');
        }
        if (!Schema::hasColumn('registered_items', 'approved_by')) {
            Schema::table('registered_items', function (Blueprint $table) {
                $table->unsignedBigInteger('approved_by')->nullable();
            });
        }
        if (!Schema::hasColumn('registered_items', 'approved_by_name')) {
            Schema::table('registered_items', function (Blueprint $table) {
                $table->string('approved_by_name')->nullable();
            });
        }
    }
    public function down(): void { /* Preserve approval history. */ }
};
