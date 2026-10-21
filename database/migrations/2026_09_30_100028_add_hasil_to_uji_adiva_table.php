<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Uji draf" (Prompt.dc.html) tests a prompt version and shows what Adiva decided
     * besides the answer: hasil {butuh_admin, tahap, alasan}.
     */
    public function up(): void
    {
        Schema::table('uji_adiva', function (Blueprint $table) {
            $table->foreignId('prompt_versi_id')->nullable()->after('kebijakan_id')->constrained('prompt_versi')->nullOnDelete();
            $table->jsonb('hasil')->nullable()->after('catatan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uji_adiva', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_versi_id');
            $table->dropColumn('hasil');
        });
    }
};
