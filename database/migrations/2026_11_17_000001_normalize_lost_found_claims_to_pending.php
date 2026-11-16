<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Previously, student requests were saved as Claimed immediately.
        // They are NOT proof that CSU verified the claimant.
        DB::table('lost_found_items')
            ->whereRaw('LOWER(report_type) = ?', ['found'])
            ->whereRaw('LOWER(status) = ?', ['claimed'])
            ->whereNotNull('claimed_by')
            ->update(['status' => 'Pending']);
    }

    public function down(): void
    {
        // Deliberately not reversed: doing so could falsely approve claims.
    }
};
