<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pipeline.dc.html: a card moved with "Maju →" / "← Mundur" reads "Dipindah manual
     * oleh Falah · baru saja". n8n clears both columns when Adiva changes the stage.
     */
    public function up(): void
    {
        Schema::table('crm_customer', function (Blueprint $table) {
            $table->foreignId('tahap_manual_oleh_id')->nullable()->after('tahap_sejak')->constrained('users')->nullOnDelete();
            $table->timestampTz('tahap_manual_at')->nullable()->after('tahap_manual_oleh_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crm_customer', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tahap_manual_oleh_id');
            $table->dropColumn('tahap_manual_at');
        });
    }
};
