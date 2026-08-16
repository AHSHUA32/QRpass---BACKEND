<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();

            // Security personnel who created the incident
            $table->foreignId('reported_by')
                ->constrained('users')
                ->cascadeOnDelete();

            // Optional registered item
            $table->foreignId('registered_item_id')
                ->nullable()
                ->constrained('registered_items')
                ->nullOnDelete();

            // QR / serial / entered code
            $table->string('scanned_code')->nullable();

            // Example: Unregistered Item, Flagged Item
            $table->string('incident_type');

            // Item information if item is not registered
            $table->string('item_name')->nullable();
            $table->string('serial_number')->nullable();

            // Location
            $table->string('gate')->default('Gate 1');

            // Flagged / Resolved
            $table->string('status')->default('Flagged');

            // Extra notes from security
            $table->text('description')->nullable();

            $table->timestamp('reported_at')->useCurrent();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
    }
};