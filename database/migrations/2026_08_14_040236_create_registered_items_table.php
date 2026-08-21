<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registered_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('item_name');
            $table->string('brand_model')->nullable();
            $table->string('serial_number')->nullable()->unique();
            $table->string('color')->nullable();
            $table->string('item_type');
            $table->string('purpose')->nullable();

            $table->string('status')->default('pending');

            $table->string('qr_code')->nullable()->unique();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registered_items');
    }
};