<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('title');

            $table->text('message');

            $table->string('audience')
                ->default('everyone');

            $table->string('priority')
                ->default('info');

            $table->dateTime('start_at')
                ->nullable();

            $table->dateTime('end_at')
                ->nullable();

            $table->boolean('is_published')
                ->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropForeign([
                'created_by',
            ]);

            $table->dropColumn([
                'title',
                'message',
                'audience',
                'priority',
                'start_at',
                'end_at',
                'is_published',
                'created_by',
            ]);
        });
    }
};