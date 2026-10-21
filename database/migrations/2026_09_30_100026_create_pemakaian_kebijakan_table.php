<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row each time Adiva answers with a policy through the KB_Kebijakan tool
     * (written by n8n). Kebijakan.dc.html: "Dipakai 64× · 30 hari" and the latest
     * customer questions.
     */
    public function up(): void
    {
        Schema::create('pemakaian_kebijakan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kebijakan_id')->constrained('kebijakan')->cascadeOnDelete();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->text('pertanyaan')->nullable();
            $table->timestampTz('waktu');
            $table->timestampsTz();

            $table->index(['kebijakan_id', 'waktu']);
        });

        Schema::table('uji_adiva', function (Blueprint $table) {
            $table->foreignId('kebijakan_id')->nullable()->after('produk_id')->constrained('kebijakan')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uji_adiva', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kebijakan_id');
        });

        Schema::dropIfExists('pemakaian_kebijakan');
    }
};
