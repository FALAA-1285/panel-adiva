<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Paket.dc.html: "terakhir diubah 12 Sep oleh Falah".
     */
    public function up(): void
    {
        Schema::table('paket_reseller', function (Blueprint $table) {
            $table->foreignId('diubah_oleh_id')->nullable()->after('status_data')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('paket_reseller', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diubah_oleh_id');
        });
    }
};
