<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// QRPASS_PERMANENT_ACCESS_V91 — additive nullable columns. No existing data deleted.
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'qrpass_revoked_at')) {
            Schema::table('users', function (Blueprint $table) { $table->timestamp('qrpass_revoked_at')->nullable(); });
        }
        if (!Schema::hasColumn('users', 'qrpass_revocation_reason')) {
            Schema::table('users', function (Blueprint $table) { $table->text('qrpass_revocation_reason')->nullable(); });
        }
    }
    public function down(): void
    {
        // Retain history on rollback. Never erase audit-relevant revocation details.
    }
};
