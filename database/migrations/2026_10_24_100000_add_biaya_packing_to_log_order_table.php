<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The packing fee of an order sent by expedition (n8n's biaya_packing): billed with
     * the products, while the expedition's shipping cost is paid COD to the courier.
     */
    public function up(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->integer('biaya_packing')->default(0)->after('ongkir');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->dropColumn('biaya_packing');
        });
    }
};
