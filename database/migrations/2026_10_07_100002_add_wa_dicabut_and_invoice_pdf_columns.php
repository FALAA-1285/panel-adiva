<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tim & akses, "Keluarkan" on a WhatsApp row: n8n refuses admin commands from
            // this number until the staff member signs in to the panel again
            $table->timestampTz('wa_dicabut_at')->nullable();
        });

        Schema::table('log_order', function (Blueprint $table) {
            // the invoice PDF n8n made (and sent), for "Unduh PDF"
            $table->text('invoice_pdf_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('log_order', function (Blueprint $table) {
            $table->dropColumn('invoice_pdf_url');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wa_dicabut_at');
        });
    }
};
