<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table LOG_ORDER. The item text columns
     * (items, items_detail) move to order_items. The customer link, bot number,
     * status timestamps and flags from the Pesanan pages are panel additions.
     */
    public function up(): void
    {
        Schema::create('log_order', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 40)->unique();
            $table->string('invoice_number', 40)->nullable()->unique();
            $table->foreignId('crm_customer_id')->nullable()->constrained('crm_customer')->nullOnDelete();
            $table->string('chat_id')->nullable()->index();
            $table->string('nomor_wa', 40)->nullable();
            $table->string('sesi_wa')->nullable();
            $table->string('nama_penerima')->nullable();
            $table->string('no_hp', 40)->nullable();
            $table->timestampTz('tanggal')->index();
            $table->string('status_order', 30)->default('MENUNGGU_KONFIRMASI')->index();
            $table->string('metode_bayar')->nullable();
            $table->string('tipe_customer', 20)->nullable();
            $table->unsignedBigInteger('total_produk')->default(0);
            $table->unsignedBigInteger('ongkir')->default(0);
            $table->decimal('berat_kg', 8, 2)->nullable();
            $table->string('kurir_pilihan')->nullable();
            $table->string('kurir_final')->nullable();
            $table->string('no_resi')->nullable();
            $table->boolean('ambil_sendiri')->default(false);
            $table->text('alamat_lengkap')->nullable();
            $table->string('kelurahan')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kode_pos', 10)->nullable();
            $table->string('jadwal_pickup')->nullable();
            $table->text('catatan_admin')->nullable();
            $table->foreignId('dicatat_oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('pesanan_tambahan')->default(false);
            $table->string('dobel_dengan', 40)->nullable();
            $table->timestampTz('bukti_masuk_at')->nullable();
            $table->timestampTz('diacc_at')->nullable();
            $table->timestampTz('lunas_at')->nullable();
            $table->timestampTz('dikirim_at')->nullable();
            $table->timestampTz('selesai_at')->nullable();
            $table->timestampTz('batas_bayar_at')->nullable();
            $table->timestampTz('diingatkan_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_order');
    }
};
