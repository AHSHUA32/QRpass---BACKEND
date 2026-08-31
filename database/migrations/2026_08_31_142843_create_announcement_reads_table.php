<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('announcement_id')
                ->constrained('announcements')
                ->cascadeOnDelete();

            $table->timestamp('viewed_at')->useCurrent();

            $table->timestamps();

            $table->unique([
                'user_id',
                'announcement_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_reads');
    }
};