<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every incoming WhatsApp message is looked up by its id before it is written
     * (CatatPesanMasuk), and WAHA may deliver the same message twice: the index makes
     * the lookup fast and the second insert fail instead of duplicating the line.
     * Partial, so the many lines without an id (Adiva, admin, system) are not affected.
     */
    public function up(): void
    {
        DB::statement('create unique index if not exists pesan_wa_message_id_unique on pesan (wa_message_id) where wa_message_id is not null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('drop index if exists pesan_wa_message_id_unique');
    }
};
