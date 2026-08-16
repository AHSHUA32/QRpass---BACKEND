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
        Schema::create('lost_found_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reported_by')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('found_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('processed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('report_type', 20)
                ->default('found');

            $table->string('item_name');

            $table->string('category')
                ->nullable();

            $table->string('brand_model')
                ->nullable();

            $table->string('color')
                ->nullable();

            $table->string('location_found')
                ->nullable();

            $table->text('description')
                ->nullable();

            $table->string('status')
                ->default('Found');

            $table->timestamp('date_found')
                ->nullable();

            $table->foreignId('claimed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('claimed_at')
                ->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lost_found_items');
    }
};