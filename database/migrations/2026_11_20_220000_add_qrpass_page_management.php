<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        foreach (['users', 'registered_items', 'lost_found_items', 'announcements'] as $table) {
            if (!Schema::hasColumn($table, 'qrpass_archived_at')) {
                Schema::table($table, function (Blueprint $t) { $t->timestamp('qrpass_archived_at')->nullable()->index(); });
            }
        }
        if (!Schema::hasTable('qrpass_management_history')) {
            Schema::create('qrpass_management_history', function (Blueprint $t) {
                $t->id(); $t->string('module'); $t->string('action'); $t->longText('record_ids');
                $t->unsignedInteger('affected')->default(0); $t->unsignedBigInteger('actor_id'); $t->timestamps();
            });
        }
    }
    public function down(): void {
        // Deliberately preserve history and flags on rollback: admin archival evidence must not be discarded silently.
    }
};
