<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// QRPASS_ATOMIC_REVOKE_DENYLIST_V180: only identifier hashes are retained.
return new class extends Migration {
    public function up(): void {
        Schema::create('qrpass_blocked_identifiers', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);
            $table->char('identifier_hash', 64);
            $table->timestamps();
            $table->unique(['kind', 'identifier_hash'], 'qrpass_blocked_identifier_unique');
        });
    }
    public function down(): void {
        Schema::dropIfExists('qrpass_blocked_identifiers');
    }
};
