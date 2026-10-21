<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the n8n workflows keep that the panel did not have yet (docs/kontrak-n8n.md §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customer', function (Blueprint $table) {
            // the cart Adiva is discussing, read back on the next chat (Rapikan Balasan)
            $table->jsonb('draft_pesanan')->nullable();
            $table->text('balasan_terakhir_bot')->nullable();
            // a question waiting for the supervisor, and the supervisor's answer (JAWAB)
            $table->jsonb('eskalasi_terbuka')->nullable();
            $table->jsonb('jawaban_supervisor')->nullable();
        });

        // messages that arrive in a burst wait here until Adiva answers them as one
        Schema::create('buffer_pesan', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id', 64)->index();
            $table->string('message_id', 128);
            $table->text('teks')->nullable();
            $table->bigInteger('waktu_ms');
        });

        // Chatbot Sosmed (Instagram / Facebook through Meta); no page in the panel
        Schema::create('sosmed_kontak', function (Blueprint $table) {
            $table->id();
            $table->string('id_pengirim', 64)->unique();
            $table->string('platform', 20)->nullable();
            $table->string('status_bot', 10)->nullable();
            $table->text('pesan_terakhir')->nullable();
            $table->text('pesan_tertunda')->nullable();
            $table->string('mid_terakhir', 128)->nullable();
            $table->text('balasan_bot_terakhir')->nullable();
            $table->string('tag_terakhir', 30)->nullable();
            $table->string('minat_order', 10)->nullable();
            $table->string('butuh_admin', 10)->nullable();
            $table->integer('jumlah_pesan')->default(0);
            $table->string('waktu_pertama', 40)->nullable();
            $table->string('waktu_terakhir', 40)->nullable();
            $table->string('waktu_notif', 40)->nullable();
            $table->string('jeda_sampai', 40)->nullable();
            $table->timestampsTz();
        });

        // the invoice PDF n8n made and sent, for "Unduh PDF" and "Kirim ulang ke customer"
        Schema::create('invoice_pdf', function (Blueprint $table) {
            $table->id();
            $table->foreignId('log_order_id')->unique()->constrained('log_order')->cascadeOnDelete();
            $table->string('nama_file', 120);
            $table->binary('isi');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_pdf');
        Schema::dropIfExists('sosmed_kontak');
        Schema::dropIfExists('buffer_pesan');

        Schema::table('crm_customer', function (Blueprint $table) {
            $table->dropColumn(['draft_pesanan', 'balasan_terakhir_bot', 'eskalasi_terbuka', 'jawaban_supervisor']);
        });
    }
};
