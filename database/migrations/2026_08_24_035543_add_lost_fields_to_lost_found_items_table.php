<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lost_found_items', function (Blueprint $table) {
            $table->foreignId('lost_by_user_id')
                ->nullable()
                ->after('found_by_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('location_lost')
                ->nullable()
                ->after('location_found');

            $table->timestamp('date_lost')
                ->nullable()
                ->after('date_found');
        });
    }

    public function down(): void
    {
        Schema::table('lost_found_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lost_by_user_id');

            $table->dropColumn([
                'location_lost',
                'date_lost',
            ]);
        });
    }
};