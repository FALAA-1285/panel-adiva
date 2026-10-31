<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The panel's OAuth access to Accurate ("Sambungkan Accurate" on Katalog → Barang toko): one
     * row. Tokens and the database session are encrypted with APP_KEY, which Vercel (where the
     * login comes back) and the VPS (where the sync runs every minute) share.
     */
    public function up(): void
    {
        Schema::create('akses_accurate', function (Blueprint $table) {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestampTz('kedaluwarsa_at')->nullable();
            // open-db.do: the database's API host and session (X-Session-ID)
            $table->string('host')->nullable();
            $table->text('session')->nullable();
            $table->timestampTz('session_at')->nullable();
            $table->foreignId('disambungkan_oleh_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('disambungkan_at');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('akses_accurate');
    }
};
