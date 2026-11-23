<?php
/* QRPass v85: irreversible removal of Announcement records and tables.
 * Back up the entire database with mysqldump BEFORE running this migration.
 * This migration deliberately cannot restore announcement data in down().
 * Other notifications and all campus item records are preserved.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'type')) {
            DB::table('notifications')->where('type', 'announcement')->delete();
        }
        if (Schema::hasTable('qrpass_management_history') && Schema::hasColumn('qrpass_management_history', 'module')) {
            DB::table('qrpass_management_history')->where('module', 'announcements')->delete();
        }
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('announcement_views');
        Schema::dropIfExists('announcements');
    }
    public function down(): void {
        // Deliberately non-reversible: recover from the pre-migration SQL backup if required.
    }
};
