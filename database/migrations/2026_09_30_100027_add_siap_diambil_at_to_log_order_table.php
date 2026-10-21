<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The SIAP action (Orders.dc.html) keeps the order status and only marks a
     * pickup order "siap diambil" once the customer has the address and pickup time.
     * Karyawan.dc.html shows it as the "Siap diambil" status.
     */
    public function up(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->timestampTz('siap_diambil_at')->nullable()->after('dikirim_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->dropColumn('siap_diambil_at');
        });
    }
};
