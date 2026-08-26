<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'role_permissions',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->string('role', 50)
                    ->unique();

                $table
                    ->boolean('register_items')
                    ->default(false);

                $table
                    ->boolean('view_qr_codes')
                    ->default(false);

                $table
                    ->boolean('approve_requests')
                    ->default(false);

                $table
                    ->boolean('scan_verify')
                    ->default(false);

                $table
                    ->boolean('view_reports')
                    ->default(false);

                $table
                    ->boolean('manage_users')
                    ->default(false);

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'role_permissions'
        );
    }
};