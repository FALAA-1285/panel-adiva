<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Path on the public disk for photos uploaded from the panel (null for photos
     * that only live at image_url, e.g. Google Drive). Needed to replace or delete them.
     */
    public function up(): void
    {
        Schema::table('gambar_produk', function (Blueprint $table) {
            $table->string('path')->nullable()->after('filename');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gambar_produk', function (Blueprint $table) {
            $table->dropColumn('path');
        });
    }
};
