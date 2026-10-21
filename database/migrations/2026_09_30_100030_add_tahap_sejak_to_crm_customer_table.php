<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Customer.dc.html: when each funnel stage was reached ("Baru 28 Sep 13:02 · Tanya
     * produk 13:03 · Nego dilewati"), as {"BARU": "2026-09-28T13:02:00+07:00", …}.
     */
    public function up(): void
    {
        Schema::table('crm_customer', function (Blueprint $table) {
            $table->jsonb('tahap_sejak')->nullable()->after('tahap_funnel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crm_customer', function (Blueprint $table) {
            $table->dropColumn('tahap_sejak');
        });
    }
};
