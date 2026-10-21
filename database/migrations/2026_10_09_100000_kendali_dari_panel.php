<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The bot is run from the panel only (docs/kontrak-n8n.md §9, §10): the panel sends the
 * supervisor's short WhatsApp notices, and the Instagram / Facebook chats get a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        // the short WhatsApp notice the panel sends for each notification
        Schema::table('notifikasi', function (Blueprint $table) {
            $table->string('wa_status', 10)->nullable();
            $table->timestampTz('wa_dikirim_at')->nullable();
            $table->index(['wa_status', 'waktu']);
        });

        // Chatbot Sosmed's contacts: who they are (Graph API profile) and who paused the bot
        Schema::table('sosmed_kontak', function (Blueprint $table) {
            $table->string('nama')->nullable();
            $table->string('username')->nullable();
            $table->text('foto_url')->nullable();
            $table->string('akun_id', 64)->nullable();
            $table->string('jeda_oleh')->nullable();
        });

        // every Instagram / Facebook message in and out
        Schema::create('sosmed_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sosmed_kontak_id')->constrained('sosmed_kontak')->cascadeOnDelete();
            $table->timestampTz('waktu')->index();
            $table->string('pengirim', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('teks')->nullable();
            $table->string('mid', 191)->nullable()->unique();
            $table->string('status_kirim', 20)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();

            $table->index(['sosmed_kontak_id', 'waktu']);
        });

        DB::unprepared(<<<'SQL'
        -- SOSMED_PESAN: a message in or out on Instagram / Facebook. A mid already stored is
        -- skipped (no row back); a contact the panel does not know yet is created.
        CREATE OR REPLACE FUNCTION n8n_catat_sosmed(j jsonb) RETURNS TABLE (id bigint) LANGUAGE plpgsql AS $$
        DECLARE
            pengirim_id text := nullif(btrim(coalesce(j ->> 'id_pengirim', '')), '');
            pengirim text := upper(coalesce(nullif(j ->> 'pengirim', ''), 'ADIVA'));
            v_mid text := nullif(btrim(coalesce(j ->> 'mid', '')), '');
            kontak bigint;
            baru bigint;
        BEGIN
            IF pengirim_id IS NULL THEN RETURN; END IF;
            IF v_mid IS NOT NULL AND EXISTS (SELECT 1 FROM sosmed_pesan s WHERE s.mid = v_mid) THEN RETURN; END IF;

            INSERT INTO sosmed_kontak (id_pengirim, platform, status_bot, nama, username, foto_url, akun_id, created_at, updated_at)
            VALUES (pengirim_id, nullif(upper(j ->> 'platform'), ''), 'AKTIF', nullif(j ->> 'nama', ''), nullif(j ->> 'username', ''),
                nullif(j ->> 'foto_url', ''), nullif(j ->> 'akun_id', ''), now(), now())
            ON CONFLICT (id_pengirim) DO UPDATE SET
                platform = coalesce(sosmed_kontak.platform, excluded.platform),
                nama = coalesce(excluded.nama, sosmed_kontak.nama),
                username = coalesce(excluded.username, sosmed_kontak.username),
                foto_url = coalesce(excluded.foto_url, sosmed_kontak.foto_url),
                akun_id = coalesce(excluded.akun_id, sosmed_kontak.akun_id),
                updated_at = now()
            RETURNING sosmed_kontak.id INTO kontak;

            INSERT INTO sosmed_pesan (sosmed_kontak_id, waktu, pengirim, teks, mid, status_kirim, meta, created_at, updated_at)
            VALUES (kontak, coalesce(n8n_waktu(j ->> 'waktu'), now()),
                CASE WHEN pengirim = ANY(ARRAY['CUSTOMER', 'ADIVA', 'ADMIN', 'SISTEM']) THEN pengirim ELSE 'ADIVA' END,
                nullif(j ->> 'teks', ''), v_mid,
                CASE WHEN pengirim = 'CUSTOMER' THEN NULL ELSE coalesce(nullif(j ->> 'status_kirim', ''), 'TERKIRIM') END,
                CASE WHEN jsonb_typeof(j -> 'meta') = 'object' THEN j -> 'meta' END, now(), now())
            RETURNING sosmed_pesan.id INTO baru;

            RETURN QUERY SELECT baru;
        END $$;

        -- the new types file under "Butuh admin"
        CREATE OR REPLACE FUNCTION n8n_notifikasi(j jsonb) RETURNS TABLE (id bigint) LANGUAGE sql AS $$
            INSERT INTO notifikasi (waktu, jenis, kategori, penerima, judul, detail, crm_customer_id, log_order_id, created_at, updated_at)
            SELECT now(), x.jenis,
                CASE
                    WHEN x.jenis IN ('JEDA_CHAT', 'BUTUH_ADMIN', 'JEDA_KEDALUWARSA', 'LAMPIRAN_MASUK', 'SOSMED_BUTUH_ADMIN') THEN 'BUTUH_ADMIN'
                    WHEN x.jenis IN ('ORDER_BARU', 'INVOICE_GAGAL', 'ORDER_KARYAWAN', 'ORDER_DITUTUP', 'ORDER_DIBATALKAN', 'BUKTI_MASUK', 'PENGIRIMAN') THEN 'ORDER'
                    ELSE 'SISTEM' END,
                coalesce(nullif(j ->> 'penerima', ''), 'ADMIN_WA'), left(coalesce(nullif(j ->> 'judul', ''), x.jenis), 255),
                nullif(j ->> 'detail', ''),
                (SELECT c.id FROM crm_customer c WHERE c.chat_id = j ->> 'chat_id'),
                (SELECT o.id FROM log_order o WHERE o.order_id = j ->> 'order_id'),
                now(), now()
            FROM (SELECT upper(coalesce(nullif(j ->> 'jenis', ''), 'WORKFLOW_ERROR')) AS jenis) x
            RETURNING notifikasi.id
        $$;

        ALTER FUNCTION n8n_catat_sosmed(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_notifikasi(jsonb) SECURITY DEFINER SET search_path = public;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS n8n_catat_sosmed(jsonb)');
        Schema::dropIfExists('sosmed_pesan');

        Schema::table('sosmed_kontak', function (Blueprint $table) {
            $table->dropColumn(['nama', 'username', 'foto_url', 'akun_id', 'jeda_oleh']);
        });

        Schema::table('notifikasi', function (Blueprint $table) {
            $table->dropIndex(['wa_status', 'waktu']);
            $table->dropColumn(['wa_status', 'wa_dikirim_at']);
        });
    }
};
