<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registered_items', function (Blueprint $table) {
            if (!Schema::hasColumn('registered_items', 'quantity')) {
                $table->unsignedInteger('quantity')
                    ->nullable()
                    ->after('purpose');
            }

            if (!Schema::hasColumn('registered_items', 'complete_description')) {
                $table->text('complete_description')
                    ->nullable()
                    ->after('quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('registered_items', function (Blueprint $table) {
            if (Schema::hasColumn('registered_items', 'complete_description')) {
                $table->dropColumn('complete_description');
            }

            if (Schema::hasColumn('registered_items', 'quantity')) {
                $table->dropColumn('quantity');
            }
        });
    }
};
