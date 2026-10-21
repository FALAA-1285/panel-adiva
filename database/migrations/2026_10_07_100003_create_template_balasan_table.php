<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Percakapan, "Template": reply texts admins saved to reuse ({nama} becomes the
     * customer's first name).
     */
    public function up(): void
    {
        Schema::create('template_balasan', function (Blueprint $table) {
            $table->id();
            $table->text('teks');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_balasan');
    }
};
