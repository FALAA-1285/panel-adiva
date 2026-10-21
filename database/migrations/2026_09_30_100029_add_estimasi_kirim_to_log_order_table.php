<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * OrderDetail.dc.html: "JNE REG · estimasi 1–2 hari", the delivery estimate the
     * courier check (Cek_Ongkir_Mengantar) gave for the chosen courier.
     */
    public function up(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->string('estimasi_kirim', 40)->nullable()->after('kurir_final');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->dropColumn('estimasi_kirim');
        });
    }
};
