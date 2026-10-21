<?php

use App\Models\PromptVersi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The whole prompt in one text, so n8n reads the active version with one query.
     */
    public function up(): void
    {
        Schema::table('prompt_versi', function (Blueprint $table) {
            $table->text('teks_lengkap')->nullable();
        });

        PromptVersi::query()->each(fn (PromptVersi $v) => $v->forceFill(['teks_lengkap' => $v->susunTeks()])->saveQuietly());
    }

    public function down(): void
    {
        Schema::table('prompt_versi', function (Blueprint $table) {
            $table->dropColumn('teks_lengkap');
        });
    }
};
