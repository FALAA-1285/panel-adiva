<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns follow the n8n data table GAMBAR_PRODUK; produk_id, caption, aktif
     * and the file dimensions are panel additions for the Gambar page.
     */
    public function up(): void
    {
        Schema::create('gambar_produk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('produk_id')->nullable()->constrained('produk')->nullOnDelete();
            $table->string('title');
            $table->text('description_for_ai')->nullable();
            $table->text('tags')->nullable();
            $table->text('caption')->nullable();
            $table->string('filename')->nullable();
            $table->text('image_url')->nullable();
            $table->string('gdrive_file_id')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('lebar')->nullable();
            $table->unsignedInteger('tinggi')->nullable();
            $table->unsignedInteger('ukuran_byte')->nullable();
            $table->boolean('aktif')->default(true);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gambar_produk');
    }
};
