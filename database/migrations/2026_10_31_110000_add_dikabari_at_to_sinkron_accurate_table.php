<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The failed run that told the supervisor the shop's goods are stale, so the next failures
     * stay quiet until a run goes through (now that the run is every minute).
     */
    public function up(): void
    {
        Schema::table('sinkron_accurate', function (Blueprint $table) {
            $table->timestampTz('dikabari_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sinkron_accurate', function (Blueprint $table) {
            $table->dropColumn('dikabari_at');
        });
    }
};
