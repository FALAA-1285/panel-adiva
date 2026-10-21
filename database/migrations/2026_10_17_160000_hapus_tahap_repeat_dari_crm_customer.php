<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('crm_customer')
            ->where('tahap_funnel', 'REPEAT')
            ->update(['tahap_funnel' => 'ORDER_DIBUAT']);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION n8n_simpan_crm(j jsonb, buat boolean DEFAULT true) RETURNS SETOF n8n_crm_customer LANGUAGE plpgsql AS $$
            DECLARE
                c crm_customer%ROWTYPE;
                chat text := nullif(btrim(j ->> 'chat_id'), '');
                tahap text;
                skor text;
                tipe text;
                optout boolean;
            BEGIN
                IF chat IS NULL THEN RETURN; END IF;
                IF buat THEN
                    INSERT INTO crm_customer (chat_id, created_at, updated_at) VALUES (chat, now(), now()) ON CONFLICT (chat_id) DO NOTHING;
                END IF;
                SELECT * INTO c FROM crm_customer WHERE chat_id = chat FOR UPDATE;
                IF NOT FOUND THEN RETURN; END IF;

                tahap := CASE 
                    WHEN upper(j ->> 'tahap_funnel') = ANY(ARRAY['BARU', 'TANYA_PRODUK', 'TANYA_HARGA', 'NEGO', 'SIAP_ORDER', 'ORDER_DIBUAT', 'CART_DITINGGAL', 'TIDAK_MINAT']) THEN upper(j ->> 'tahap_funnel')
                    WHEN upper(j ->> 'tahap_funnel') = 'REPEAT' THEN 'ORDER_DIBUAT'
                    ELSE c.tahap_funnel END;
                skor := CASE WHEN upper(j ->> 'skor_prospek') = ANY(ARRAY['HOT', 'WARM', 'COLD']) THEN upper(j ->> 'skor_prospek') ELSE c.skor_prospek END;
                tipe := CASE WHEN upper(j ->> 'tipe_customer') = ANY(ARRAY['RESELLER', 'RETAIL', 'KARYAWAN', 'BELUM_JELAS']) THEN upper(j ->> 'tipe_customer') ELSE c.tipe_customer END;
                optout := CASE WHEN j ? 'status_optout' THEN n8n_ya(j ->> 'status_optout') ELSE c.status_optout END;

                UPDATE crm_customer SET
                    nomor_wa = coalesce(nullif(j ->> 'nomor_wa', ''), nomor_wa),
                    nama = coalesce(nullif(j ->> 'nama', ''), nama),
                    sesi_wa = coalesce(nullif(j ->> 'sesi_wa', ''), sesi_wa),
                    tipe_customer = tipe,
                    tahap_funnel = tahap,
                    skor_prospek = skor,
                    tahap_sejak = CASE WHEN tahap IS DISTINCT FROM c.tahap_funnel
                        THEN coalesce(tahap_sejak, '{}'::jsonb) || jsonb_build_object(tahap, now()) ELSE tahap_sejak END,
                    tahap_manual_oleh_id = CASE WHEN tahap IS DISTINCT FROM c.tahap_funnel THEN NULL ELSE tahap_manual_oleh_id END,
                    tahap_manual_at = CASE WHEN tahap IS DISTINCT FROM c.tahap_funnel THEN NULL ELSE tahap_manual_at END,
                    minat_produk = CASE WHEN j ? 'minat_produk' THEN nullif(j ->> 'minat_produk', '') ELSE minat_produk END,
                    lokasi_jualan = CASE WHEN j ? 'lokasi_jualan' THEN nullif(j ->> 'lokasi_jualan', '') ELSE lokasi_jualan END,
                    estimasi_modal = CASE WHEN j ? 'estimasi_modal' THEN n8n_angka(j ->> 'estimasi_modal') ELSE estimasi_modal END,
                    kota = CASE WHEN j ? 'kota' THEN nullif(j ->> 'kota', '') ELSE kota END,
                    ringkasan_terakhir = CASE WHEN j ? 'ringkasan_terakhir' THEN nullif(j ->> 'ringkasan_terakhir', '') ELSE ringkasan_terakhir END,
                    pesan_terakhir_customer = CASE WHEN j ? 'pesan_terakhir_customer' THEN nullif(j ->> 'pesan_terakhir_customer', '') ELSE pesan_terakhir_customer END,
                    balasan_terakhir_bot = CASE WHEN j ? 'balasan_terakhir_bot' THEN nullif(j ->> 'balasan_terakhir_bot', '') ELSE balasan_terakhir_bot END,
                    jumlah_followup = CASE WHEN j ? 'jumlah_followup' THEN coalesce(n8n_angka(j ->> 'jumlah_followup'), 0)::int ELSE jumlah_followup END,
                    tgl_interaksi_terakhir = CASE WHEN j ? 'tgl_interaksi_terakhir' THEN coalesce(n8n_waktu(j ->> 'tgl_interaksi_terakhir'), tgl_interaksi_terakhir) ELSE tgl_interaksi_terakhir END,
                    tgl_followup_berikutnya = CASE WHEN j ? 'tgl_followup_berikutnya' THEN n8n_waktu(j ->> 'tgl_followup_berikutnya') ELSE tgl_followup_berikutnya END,
                    status_optout = optout,
                    butuh_admin = CASE WHEN j ? 'butuh_admin' THEN n8n_ya(j ->> 'butuh_admin') ELSE butuh_admin END,
                    alasan_butuh_admin = CASE
                        WHEN j ? 'butuh_admin' AND NOT n8n_ya(j ->> 'butuh_admin') THEN NULL
                        WHEN j ? 'alasan_butuh_admin' THEN nullif(j ->> 'alasan_butuh_admin', '')
                        ELSE alasan_butuh_admin END,
                    catatan = CASE WHEN j ? 'catatan' THEN nullif(j ->> 'catatan', '') ELSE catatan END,
                    mem_gen = CASE WHEN j ? 'mem_gen' THEN coalesce(n8n_angka(j ->> 'mem_gen')::int, mem_gen) ELSE mem_gen END,
                    draft_pesanan = CASE WHEN j ? 'draft_pesanan' THEN n8n_json(j -> 'draft_pesanan') ELSE draft_pesanan END,
                    eskalasi_terbuka = CASE WHEN j ? 'eskalasi_terbuka' THEN n8n_json(j -> 'eskalasi_terbuka') ELSE eskalasi_terbuka END,
                    jawaban_supervisor = CASE WHEN j ? 'jawaban_supervisor' THEN n8n_json(j -> 'jawaban_supervisor') ELSE jawaban_supervisor END,
                    updated_at = now()
                WHERE id = c.id;

                -- a reply to a recent campaign: what it led to (Broadcast → Laporan)
                IF tahap IS DISTINCT FROM c.tahap_funnel OR (skor = 'HOT' AND c.skor_prospek <> 'HOT') OR (optout AND NOT c.status_optout) THEN
                    UPDATE broadcast_penerima SET
                        naik_tahap = naik_tahap OR tahap IS DISTINCT FROM c.tahap_funnel,
                        jadi_hot = jadi_hot OR (skor = 'HOT' AND c.skor_prospek <> 'HOT'),
                        minta_stop = minta_stop OR (optout AND NOT c.status_optout),
                        updated_at = now()
                    WHERE id = (SELECT id FROM broadcast_penerima WHERE chat_id = chat AND status = 'TERKIRIM'
                        AND terkirim_at > now() - interval '3 days' ORDER BY terkirim_at DESC LIMIT 1);
                END IF;

                -- messages that came in before the customer had a row
                UPDATE pesan SET crm_customer_id = c.id WHERE chat_id = chat AND crm_customer_id IS NULL;

                RETURN QUERY SELECT * FROM n8n_crm_customer WHERE id = c.id;
            END $$;
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
