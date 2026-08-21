<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('registered_item_id')
                ->constrained('registered_items')
                ->cascadeOnDelete();

            $table->foreignId('scanned_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('qr_code');
            $table->string('gate')->default('Gate 1');
            $table->string('direction'); // IN or OUT
            $table->string('result')->default('Verified');

            $table->timestamp('scanned_at')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};