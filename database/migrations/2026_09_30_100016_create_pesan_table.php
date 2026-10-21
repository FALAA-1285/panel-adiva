<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every WhatsApp chat line: from the customer, Adiva, an admin, or a system note.
     * jalur is how the incoming message was routed (A/B/C/D or DIABAIKAN).
     */
    public function up(): void
    {
        Schema::create('pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->string('chat_id')->index();
            $table->string('sesi_wa')->nullable();
            $table->timestampTz('waktu')->index();
            $table->string('pengirim', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('teks')->nullable();
            $table->foreignId('gambar_produk_id')->nullable()->constrained('gambar_produk')->nullOnDelete();
            $table->text('lampiran_url')->nullable();
            $table->string('lampiran_keterangan')->nullable();
            $table->jsonb('tombol')->nullable();
            $table->string('jalur', 20)->nullable();
            $table->string('status_kirim', 20)->nullable();
            $table->string('wa_message_id')->nullable();
            $table->foreignId('broadcast_id')->nullable()->constrained('broadcast')->nullOnDelete();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->index(['crm_customer_id', 'waktu']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesan');
    }
};
