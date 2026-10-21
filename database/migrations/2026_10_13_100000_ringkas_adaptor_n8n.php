<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two reads the chatbot workflow repeated node per node (docs/kontrak-n8n.md §8).
 *
 * n8n_konteks_awal() replaces the four CONFIG_TOKO / ADMIN_STAFF reads that ran one after
 * another on every incoming message (ADMIN_WA, SARAH_WA, DAFTAR_NOMOR_BOT, the staff list)
 * and the three the 09:00 reminder ran again: one row, one round trip.
 *
 * n8n_boleh_balas() is the pause check at C, D5 and D5b, which stood as the same
 * 180-character query in three nodes. It still hands back one row when the bot may answer
 * and none when the customer is paused, so the branches behind it work as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(self::BACA);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS n8n_konteks_awal(), n8n_boleh_balas(jsonb);');
    }

    private const BACA = <<<'SQL'
        -- everything the entry branch used to read one node at a time, as one row
        CREATE OR REPLACE FUNCTION n8n_konteks_awal() RETURNS TABLE(
            admin_wa text, sarah_wa text, sesi_utama text, daftar_nomor_bot text, staf jsonb
        ) LANGUAGE sql STABLE AS $$
            SELECT (SELECT nilai FROM config_toko WHERE kunci = 'ADMIN_WA'),
                   (SELECT nilai FROM config_toko WHERE kunci = 'SARAH_WA'),
                   (SELECT nilai FROM config_toko WHERE kunci = 'SESI_UTAMA'),
                   (SELECT nilai FROM config_toko WHERE kunci = 'DAFTAR_NOMOR_BOT'),
                   coalesce((SELECT jsonb_agg(to_jsonb(s) ORDER BY s.nama) FROM n8n_admin_staff s), '[]'::jsonb)
        $$;

        -- one row while the bot may still answer this customer, none once a CS paused it
        CREATE OR REPLACE FUNCTION n8n_boleh_balas(j jsonb) RETURNS TABLE(boleh boolean) LANGUAGE sql STABLE AS $$
            SELECT true WHERE NOT EXISTS (
                SELECT 1 FROM jeda_bot
                WHERE selesai_at IS NULL AND (sampai IS NULL OR sampai > now())
                  AND (nomor = n8n_nomor(j ->> 'nomor') OR lower(chat_id) = lower(j ->> 'chat_id')))
        $$;
        SQL;
};
