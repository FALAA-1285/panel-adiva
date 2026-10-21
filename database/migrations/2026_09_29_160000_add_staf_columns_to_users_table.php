<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * nomor_wa + cabang replace the n8n ADMIN_STAFF table: messages from these
     * numbers are treated as admin commands.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nomor_wa', 40)->nullable()->unique()->after('email');
            $table->string('cabang')->nullable()->after('nomor_wa');
            $table->string('peran', 20)->default('STAF_CS')->after('cabang');
            $table->timestampTz('diundang_at')->nullable();
            $table->timestampTz('nonaktif_at')->nullable();
            $table->timestampTz('terakhir_masuk_at')->nullable();
            $table->string('terakhir_masuk_perangkat')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nomor_wa']);
            $table->dropColumn([
                'nomor_wa', 'cabang', 'peran',
                'diundang_at', 'nonaktif_at', 'terakhir_masuk_at', 'terakhir_masuk_perangkat',
            ]);
        });
    }
};
