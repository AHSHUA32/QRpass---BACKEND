<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'security_incidents',
            function (Blueprint $table) {
                if (
                    !Schema::hasColumn(
                        'security_incidents',
                        'resolution_details'
                    )
                ) {
                    $table->text(
                        'resolution_details'
                    )->nullable()->after(
                        'description'
                    );
                }

                if (
                    !Schema::hasColumn(
                        'security_incidents',
                        'resolved_by'
                    )
                ) {
                    $table->unsignedBigInteger(
                        'resolved_by'
                    )->nullable()->after(
                        'resolution_details'
                    );
                }

                if (
                    !Schema::hasColumn(
                        'security_incidents',
                        'resolved_at'
                    )
                ) {
                    $table->timestamp(
                        'resolved_at'
                    )->nullable()->after(
                        'resolved_by'
                    );
                }
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'security_incidents',
            function (Blueprint $table) {
                if (
                    Schema::hasColumn(
                        'security_incidents',
                        'resolved_at'
                    )
                ) {
                    $table->dropColumn(
                        'resolved_at'
                    );
                }

                if (
                    Schema::hasColumn(
                        'security_incidents',
                        'resolved_by'
                    )
                ) {
                    $table->dropColumn(
                        'resolved_by'
                    );
                }

                if (
                    Schema::hasColumn(
                        'security_incidents',
                        'resolution_details'
                    )
                ) {
                    $table->dropColumn(
                        'resolution_details'
                    );
                }
            }
        );
    }
};
