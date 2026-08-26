<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            // General Settings
            $table->string('system_name')->default('QRpass');
            $table->string('institution')->default('University of Cebu - Main Campus');
            $table->string('academic_year')->default('2026-2027');
            $table->string('semester')->default('1st Semester');

            // QR & Security Policy
            $table->unsignedSmallInteger('session_timeout')->default(30);
            $table->unsignedTinyInteger('max_login_attempts')->default(5);
            $table->unsignedSmallInteger('qr_code_validity_months')->default(6);

            // Security Options
            $table->boolean('two_factor_enabled')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};