<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Audit log for the Log aktivitas page. pelaku keeps the display name so rows
     * from Adiva / Sistem (no user) and from removed staff stay readable.
     */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('waktu')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pelaku');
            $table->string('sumber', 20);
            $table->string('kategori', 20);
            $table->string('aksi');
            $table->text('detail')->nullable();
            $table->nullableMorphs('subjek');
            $table->jsonb('data')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
