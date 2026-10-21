<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Versions of Adiva's system prompt; bagian holds the numbered sections
     * as [{no, judul, teks}].
     */
    public function up(): void
    {
        Schema::create('prompt_versi', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('versi')->unique();
            $table->string('ringkasan');
            $table->jsonb('bagian');
            $table->string('status', 10)->default('DRAF');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('diterbitkan_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prompt_versi');
    }
};
