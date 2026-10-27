<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A product no longer sold is archived, not deleted: its past order lines and photos
     * keep pointing at it, and it can be brought back.
     */
    public function up(): void
    {
        Schema::table('produk', function (Blueprint $table) {
            $table->timestampTz('diarsip_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('produk', function (Blueprint $table) {
            $table->dropColumn('diarsip_at');
        });
    }
};
