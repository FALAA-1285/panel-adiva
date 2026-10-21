<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The layer the n8n workflows talk to (docs/kontrak-n8n.md §8).
 *
 * The workflows were written against n8n Data Tables: times as "2026-09-28 13:42" WIB
 * text, YA/TIDAK instead of booleans, an order's items as text plus a JSON list. The
 * n8n_* views hand rows back in that shape, so the workflows' Code nodes keep working,
 * and the n8n_* functions take one JSON object per write and turn it into the panel's
 * types (and keep the panel's extra columns right: tahap_sejak, diacc_at, order_items, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        // a panel command (request_id) is carried out once, however often it is sent
        Schema::create('n8n_perintah', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->string('perintah', 30);
            $table->jsonb('jawaban')->nullable();
            $table->timestampsTz();
        });

        DB::unprepared(self::BANTU);
        DB::unprepared(self::VIEW);
        DB::unprepared(self::TULIS);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS n8n_simpan_crm(jsonb, boolean), n8n_catat_order(jsonb), n8n_ubah_order(jsonb),
                n8n_jeda(jsonb), n8n_akhiri_jeda(jsonb), n8n_catat_pesan(jsonb), n8n_pesan_dibaca(text),
                n8n_notifikasi(jsonb), n8n_event(jsonb), n8n_simpan_invoice_pdf(jsonb), n8n_simpan_arsip(jsonb), n8n_hapus_arsip(jsonb);
            DROP VIEW IF EXISTS n8n_crm_customer, n8n_log_order, n8n_jeda_bot, n8n_admin_staff,
                n8n_template_followup, n8n_gambar_produk;
            DROP FUNCTION IF EXISTS n8n_wib(timestamptz), n8n_waktu(text), n8n_angka(text), n8n_desimal(text),
                n8n_ya(text), n8n_json(jsonb), n8n_nomor(text), n8n_nama_admin(text), n8n_tambah_jam_kerja(timestamptz, integer);
            SQL);

        Schema::dropIfExists('n8n_perintah');
    }

    private const BANTU = <<<'SQL'
        -- "2026-09-28 13:42" in WIB, as the Data Tables held times
        CREATE OR REPLACE FUNCTION n8n_wib(t timestamptz) RETURNS text LANGUAGE sql STABLE AS $$
            SELECT to_char(t AT TIME ZONE 'Asia/Jakarta', 'YYYY-MM-DD HH24:MI')
        $$;

        -- "2026-09-28 13:42" (WIB) or an ISO time with its zone; empty or unreadable: null
        CREATE OR REPLACE FUNCTION n8n_waktu(t text) RETURNS timestamptz LANGUAGE plpgsql STABLE AS $$
        BEGIN
            IF t IS NULL OR btrim(t) = '' THEN RETURN NULL; END IF;
            IF btrim(t) ~ '(Z|z|[+-]\d{2}:?\d{2})$' AND t ~ '\d{2}:\d{2}' AND t ~ 'T' THEN RETURN btrim(t)::timestamptz; END IF;
            RETURN btrim(t)::timestamp AT TIME ZONE 'Asia/Jakarta';
        EXCEPTION WHEN others THEN RETURN NULL;
        END $$;

        -- "Rp35.000" → 35000, "5 jt" → 5000000, "25rb" → 25000
        CREATE OR REPLACE FUNCTION n8n_angka(t text) RETURNS bigint LANGUAGE plpgsql IMMUTABLE AS $$
        DECLARE
            s text := replace(lower(coalesce(t, '')), 'rp', '');
            m text[];
            kali numeric := 1;
        BEGIN
            m := regexp_match(s, '(\d+(?:[.,]\d+)*)');
            IF m IS NULL THEN RETURN NULL; END IF;
            IF s ~ '(jt|juta)' THEN kali := 1000000; ELSIF s ~ '(rb|ribu|\dk\b|\sk\b)' THEN kali := 1000; END IF;
            IF m[1] ~ '^\d{1,3}([.,]\d{3})+$' THEN
                RETURN round(regexp_replace(m[1], '[.,]', '', 'g')::numeric * kali);
            END IF;
            RETURN round(replace(m[1], ',', '.')::numeric * kali);
        EXCEPTION WHEN others THEN RETURN NULL;
        END $$;

        -- "1,6" / "1.6 kg" → 1.6
        CREATE OR REPLACE FUNCTION n8n_desimal(t text) RETURNS numeric LANGUAGE plpgsql IMMUTABLE AS $$
        DECLARE m text[] := regexp_match(coalesce(t, ''), '(\d+(?:[.,]\d+)?)');
        BEGIN
            RETURN CASE WHEN m IS NULL THEN NULL ELSE replace(m[1], ',', '.')::numeric END;
        EXCEPTION WHEN others THEN RETURN NULL;
        END $$;

        CREATE OR REPLACE FUNCTION n8n_ya(t text) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
            SELECT upper(btrim(coalesce(t, ''))) IN ('YA', 'Y', 'TRUE', '1')
        $$;

        -- a JSON value, or JSON written as text by a Code node ("" → null)
        CREATE OR REPLACE FUNCTION n8n_json(v jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
        BEGIN
            IF v IS NULL OR v = 'null'::jsonb THEN RETURN NULL; END IF;
            IF jsonb_typeof(v) = 'string' THEN
                IF btrim(v #>> '{}') = '' THEN RETURN NULL; END IF;
                RETURN (v #>> '{}')::jsonb;
            END IF;
            RETURN v;
        EXCEPTION WHEN others THEN RETURN NULL;
        END $$;

        -- "6281…@c.us", "0812…", "812…" → "62812…"
        CREATE OR REPLACE FUNCTION n8n_nomor(t text) RETURNS text LANGUAGE sql IMMUTABLE AS $$
            SELECT CASE
                WHEN d = '' THEN NULL
                WHEN left(d, 1) = '0' THEN '62' || substr(d, 2)
                WHEN left(d, 1) = '8' THEN '62' || d
                ELSE d END
            FROM (SELECT regexp_replace(split_part(coalesce(t, ''), '@', 1), '\D', '', 'g') AS d) x
        $$;

        -- the staff member behind an admin chat id, or the chat id itself
        CREATE OR REPLACE FUNCTION n8n_nama_admin(t text) RETURNS text LANGUAGE sql STABLE AS $$
            SELECT coalesce((SELECT name FROM users WHERE nomor_wa = n8n_nomor(t) ORDER BY nonaktif_at NULLS FIRST LIMIT 1), nullif(t, ''))
        $$;

        -- the moment $jam working hours after $dari (App\Support\JamKerja::tambah)
        CREATE OR REPLACE FUNCTION n8n_tambah_jam_kerja(dari timestamptz, jam integer) RETURNS timestamptz LANGUAGE plpgsql STABLE AS $$
        DECLARE
            cfg_jam text := (SELECT nilai FROM config_toko WHERE kunci = 'JAM_PESAN_OTOMATIS');
            cfg_libur text := (SELECT nilai FROM config_toko WHERE kunci = 'HARI_LIBUR_OTOMATIS');
            nama_hari text[] := ARRAY['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            mulai time;
            selesai time;
            libur text[];
            sisa integer := jam * 60;
            awal timestamp := dari AT TIME ZONE 'Asia/Jakarta';
            akhir timestamp := awal;
            hari date := awal::date;
            buka timestamp;
            tutup timestamp;
            ada integer;
        BEGIN
            mulai := coalesce(nullif(btrim(split_part(coalesce(cfg_jam, ''), '-', 1)), ''), '07:00')::time;
            selesai := coalesce(nullif(btrim(split_part(coalesce(cfg_jam, ''), '-', 2)), ''), '21:00')::time;
            libur := ARRAY(SELECT btrim(x) FROM unnest(string_to_array(coalesce(cfg_libur, ''), ',')) x WHERE btrim(x) <> '');
            FOR i IN 0..365 LOOP
                IF NOT (nama_hari[extract(dow FROM hari)::int + 1] = ANY(libur) OR hari::text = ANY(libur)) THEN
                    buka := greatest(awal, hari + mulai);
                    tutup := hari + selesai;
                    IF tutup > buka THEN
                        ada := floor(extract(epoch FROM (tutup - buka)) / 60)::int;
                        IF ada >= sisa THEN RETURN (buka + make_interval(mins => sisa)) AT TIME ZONE 'Asia/Jakarta'; END IF;
                        sisa := sisa - ada;
                        akhir := tutup;
                    END IF;
                END IF;
                hari := hari + 1;
            END LOOP;
            RETURN akhir AT TIME ZONE 'Asia/Jakarta';
        END $$;
        SQL;

    private const VIEW = <<<'SQL'
        CREATE OR REPLACE VIEW n8n_crm_customer AS
        SELECT c.id, c.chat_id, c.nomor_wa, c.nama, c.sesi_wa, c.tipe_customer, c.tahap_funnel, c.skor_prospek,
            c.minat_produk, c.lokasi_jualan, c.estimasi_modal, c.kota, c.ringkasan_terakhir, c.pesan_terakhir_customer,
            c.balasan_terakhir_bot, c.jumlah_followup,
            n8n_wib(c.tgl_interaksi_terakhir) AS tgl_interaksi_terakhir,
            n8n_wib(c.tgl_followup_berikutnya) AS tgl_followup_berikutnya,
            CASE WHEN c.status_optout THEN 'YA' ELSE 'TIDAK' END AS status_optout,
            CASE WHEN c.butuh_admin THEN 'YA' ELSE 'TIDAK' END AS butuh_admin,
            c.alasan_butuh_admin, c.catatan, c.catatan_tetap, c.mem_gen,
            c.draft_pesanan::text AS draft_pesanan, c.eskalasi_terbuka::text AS eskalasi_terbuka,
            c.jawaban_supervisor::text AS jawaban_supervisor,
            -- a campaign sent in the last 3 days with "konteks" on: Adiva reads what it said
            (SELECT b.pesan FROM broadcast_penerima bp JOIN broadcast b ON b.id = bp.broadcast_id
                WHERE bp.chat_id = c.chat_id AND bp.status = 'TERKIRIM' AND bp.terkirim_at > now() - interval '3 days'
                    AND coalesce((b.opsi ->> 'konteks')::boolean, false)
                ORDER BY bp.terkirim_at DESC LIMIT 1) AS broadcast_konteks
        FROM crm_customer c;

        CREATE OR REPLACE VIEW n8n_log_order AS
        SELECT o.id, o.order_id, o.invoice_number, o.chat_id, o.nomor_wa, o.sesi_wa, o.nama_penerima, o.no_hp,
            n8n_wib(o.tanggal) AS tanggal, o.status_order, o.metode_bayar, o.tipe_customer, o.total_produk, o.ongkir,
            o.berat_kg, o.kurir_pilihan, o.kurir_final, o.no_resi, o.alamat_lengkap, o.kelurahan, o.kecamatan, o.kota,
            o.provinsi, o.kode_pos, o.catatan_admin, o.invoice_pdf_url,
            (SELECT string_agg(i.nama_produk || ' (' || i.qty || ')', ', ' ORDER BY i.urutan, i.id)
                FROM order_items i WHERE i.log_order_id = o.id) AS items,
            coalesce((SELECT json_agg(json_build_object('nama', i.nama_produk, 'qty', i.qty, 'harga_satuan', i.harga_satuan, 'subtotal', i.subtotal) ORDER BY i.urutan, i.id)
                FROM order_items i WHERE i.log_order_id = o.id)::text, '[]') AS items_detail,
            n8n_wib(o.diacc_at) AS diacc_at, n8n_wib(o.lunas_at) AS lunas_at, n8n_wib(o.dikirim_at) AS dikirim_at,
            n8n_wib(o.selesai_at) AS selesai_at, n8n_wib(o.batas_bayar_at) AS batas_bayar_at,
            n8n_wib(o.bukti_masuk_at) AS bukti_masuk_at
        FROM log_order o;

        -- every pause; the ones still running have selesai_at null
        CREATE OR REPLACE VIEW n8n_jeda_bot AS
        SELECT j.id, j.nomor, j.chat_id, j.nama, j.sesi_wa, n8n_wib(j.sejak) AS sejak, n8n_wib(j.sampai) AS sampai,
            j.oleh, j.alasan, CASE WHEN j.sudah_diberi_tahu THEN 'YA' ELSE '' END AS sudah_diberi_tahu,
            n8n_wib(j.selesai_at) AS selesai_at
        FROM jeda_bot j;

        -- staff who may type admin commands on WhatsApp (Tim & akses); "Keluarkan" sets dicabut
        CREATE OR REPLACE VIEW n8n_admin_staff AS
        SELECT u.name AS nama, u.nomor_wa, u.cabang, CASE WHEN u.wa_dicabut_at IS NOT NULL THEN 'YA' ELSE '' END AS dicabut
        FROM users u
        WHERE u.nomor_wa IS NOT NULL AND u.nomor_wa <> '' AND u.nonaktif_at IS NULL;

        CREATE OR REPLACE VIEW n8n_template_followup AS
        SELECT t.kode, t.judul, t.kondisi_pemicu, t.tahap, t.acuan_waktu, t.pesan, t.jeda_hari,
            CASE WHEN t.status_aktif THEN 'YA' ELSE 'TIDAK' END AS status_aktif
        FROM template_followup t;

        CREATE OR REPLACE VIEW n8n_gambar_produk AS
        SELECT g.id, g.produk_id, g.title, g.description_for_ai, g.tags, g.caption, g.filename, g.image_url,
            g.gdrive_file_id, g.mime_type
        FROM gambar_produk g
        WHERE g.aktif;
        SQL;

    private const TULIS = <<<'SQL'
        -- CRM_CUSTOMER upsert/update: only the keys present in j change
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

            tahap := CASE WHEN upper(j ->> 'tahap_funnel') = ANY(ARRAY['BARU', 'TANYA_PRODUK', 'TANYA_HARGA', 'NEGO', 'SIAP_ORDER', 'ORDER_DIBUAT', 'REPEAT', 'CART_DITINGGAL', 'TIDAK_MINAT'])
                THEN upper(j ->> 'tahap_funnel') ELSE c.tahap_funnel END;
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

        -- LOG_ORDER insert (Catat Order, Catat Order Manual): the items become order_items
        CREATE OR REPLACE FUNCTION n8n_catat_order(j jsonb) RETURNS SETOF n8n_log_order LANGUAGE plpgsql AS $$
        DECLARE
            v_id bigint;
            cust bigint;
            det jsonb := n8n_json(j -> 'items_detail');
            it jsonb;
            urut integer := 0;
            q integer;
            h bigint;
            kurir text := nullif(btrim(coalesce(j ->> 'kurir_pilihan', '')), '');
            total bigint := coalesce(n8n_angka(j ->> 'total_produk'), 0);
        BEGIN
            SELECT id INTO cust FROM crm_customer WHERE chat_id = j ->> 'chat_id';
            INSERT INTO log_order (order_id, tanggal, chat_id, crm_customer_id, nomor_wa, sesi_wa, nama_penerima, no_hp,
                alamat_lengkap, kelurahan, kecamatan, kota, provinsi, kode_pos, metode_bayar, tipe_customer, status_order,
                total_produk, ongkir, berat_kg, kurir_pilihan, ambil_sendiri, catatan_admin, created_at, updated_at)
            VALUES (j ->> 'order_id', coalesce(n8n_waktu(j ->> 'tanggal'), now()), j ->> 'chat_id', cust,
                nullif(j ->> 'nomor_wa', ''), nullif(j ->> 'sesi_wa', ''), nullif(j ->> 'nama_penerima', ''), nullif(j ->> 'no_hp', ''),
                nullif(j ->> 'alamat_lengkap', ''), nullif(j ->> 'kelurahan', ''), nullif(j ->> 'kecamatan', ''), nullif(j ->> 'kota', ''),
                nullif(j ->> 'provinsi', ''), nullif(j ->> 'kode_pos', ''), nullif(j ->> 'metode_bayar', ''),
                CASE WHEN upper(j ->> 'tipe_customer') = ANY(ARRAY['RESELLER', 'RETAIL', 'KARYAWAN', 'BELUM_JELAS']) THEN upper(j ->> 'tipe_customer') END,
                CASE WHEN upper(j ->> 'status_order') = ANY(ARRAY['MENUNGGU_KONFIRMASI', 'KARYAWAN']) THEN upper(j ->> 'status_order') ELSE 'MENUNGGU_KONFIRMASI' END,
                total, coalesce(n8n_angka(j ->> 'ongkir'), 0), n8n_desimal(j ->> 'berat_kg'), kurir,
                coalesce(kurir ~* '(AMBIL SENDIRI|PICKUP)', false), nullif(j ->> 'catatan_admin', ''), now(), now())
            ON CONFLICT (order_id) DO NOTHING
            RETURNING id INTO v_id;

            IF v_id IS NULL THEN
                RETURN QUERY SELECT * FROM n8n_log_order WHERE order_id = j ->> 'order_id';
                RETURN;
            END IF;

            IF jsonb_typeof(det) = 'array' AND jsonb_array_length(det) > 0 THEN
                FOR it IN SELECT * FROM jsonb_array_elements(det) LOOP
                    urut := urut + 1;
                    q := greatest(1, coalesce(n8n_angka(it ->> 'qty'), 1))::int;
                    h := coalesce(n8n_angka(it ->> 'harga_satuan'), n8n_angka(it ->> 'harga'), 0);
                    INSERT INTO order_items (log_order_id, urutan, nama_produk, qty, harga_satuan, subtotal, created_at, updated_at)
                    VALUES (v_id, urut, left(coalesce(nullif(it ->> 'nama', ''), nullif(it ->> 'nama_produk', ''), '-'), 255), q, h, q * h, now(), now());
                END LOOP;
            ELSIF nullif(btrim(coalesce(j ->> 'items', '')), '') IS NOT NULL THEN
                INSERT INTO order_items (log_order_id, urutan, nama_produk, qty, harga_satuan, subtotal, created_at, updated_at)
                VALUES (v_id, 1, left(j ->> 'items', 255), 1, total, total, now(), now());
            END IF;

            UPDATE broadcast_penerima SET log_order_id = v_id, updated_at = now()
            WHERE log_order_id IS NULL AND id = (SELECT id FROM broadcast_penerima WHERE chat_id = j ->> 'chat_id'
                AND status = 'TERKIRIM' AND terkirim_at > now() - interval '3 days' ORDER BY terkirim_at DESC LIMIT 1);

            RETURN QUERY SELECT * FROM n8n_log_order WHERE id = v_id;
        END $$;

        -- LOG_ORDER update by order_id. Status changes fill the panel's timestamps; "riwayat"
        -- adds a line to the order's history; "status_lama" only updates from that status.
        CREATE OR REPLACE FUNCTION n8n_ubah_order(j jsonb) RETURNS SETOF n8n_log_order LANGUAGE plpgsql AS $$
        DECLARE
            o log_order%ROWTYPE;
            st text;
            perintah text := upper(coalesce(j ->> 'perintah', ''));
            jam_bayar bigint;
        BEGIN
            SELECT * INTO o FROM log_order WHERE order_id = j ->> 'order_id' FOR UPDATE;
            IF NOT FOUND THEN RETURN; END IF;
            IF j ? 'status_lama' AND o.status_order <> (j ->> 'status_lama') THEN RETURN; END IF;

            st := CASE WHEN upper(j ->> 'status_order') = ANY(ARRAY['MENUNGGU_KONFIRMASI', 'DIACC', 'LUNAS', 'DIKIRIM', 'SELESAI', 'KARYAWAN', 'DITOLAK', 'DIBATALKAN', 'BATAL OTOMATIS', 'DITUTUP'])
                THEN upper(j ->> 'status_order') ELSE o.status_order END;

            IF st = 'DIACC' AND o.status_order <> 'DIACC' THEN
                jam_bayar := CASE
                    WHEN concat_ws(' ', o.metode_bayar, o.kurir_pilihan) ~* '(BAYAR DI TEMPAT|POTONG GAJI)' THEN NULL
                    WHEN concat_ws(' ', o.metode_bayar, o.kurir_pilihan) ~* '(AMBIL SENDIRI|PICKUP)'
                        THEN coalesce(n8n_angka((SELECT nilai FROM config_toko WHERE kunci = 'BATAS_BAYAR_PICKUP_JAM')), n8n_angka((SELECT nilai FROM config_toko WHERE kunci = 'BATAS_BAYAR_JAM')))
                    ELSE n8n_angka((SELECT nilai FROM config_toko WHERE kunci = 'BATAS_BAYAR_JAM')) END;
            END IF;

            UPDATE log_order SET
                status_order = st,
                catatan_admin = CASE WHEN j ? 'catatan_admin' THEN nullif(j ->> 'catatan_admin', '') ELSE catatan_admin END,
                ongkir = CASE WHEN j ? 'ongkir' AND n8n_angka(j ->> 'ongkir') IS NOT NULL THEN n8n_angka(j ->> 'ongkir') ELSE ongkir END,
                no_resi = CASE WHEN j ? 'no_resi' THEN nullif(j ->> 'no_resi', '') ELSE no_resi END,
                kurir_final = CASE WHEN j ? 'kurir_final' THEN nullif(j ->> 'kurir_final', '') ELSE kurir_final END,
                invoice_number = coalesce(nullif(j ->> 'invoice_number', ''), invoice_number),
                invoice_pdf_url = coalesce(nullif(j ->> 'invoice_pdf_url', ''), invoice_pdf_url),
                diacc_at = CASE WHEN st = 'DIACC' AND o.status_order <> 'DIACC' THEN now() ELSE diacc_at END,
                batas_bayar_at = CASE WHEN st = 'DIACC' AND o.status_order <> 'DIACC'
                    THEN CASE WHEN jam_bayar IS NULL THEN NULL ELSE n8n_tambah_jam_kerja(now(), jam_bayar::int) END
                    ELSE batas_bayar_at END,
                lunas_at = CASE WHEN st = 'LUNAS' AND o.status_order <> 'LUNAS' THEN now() ELSE lunas_at END,
                dikirim_at = CASE WHEN st = 'DIKIRIM' AND o.status_order <> 'DIKIRIM' THEN now() ELSE dikirim_at END,
                selesai_at = CASE WHEN st = 'SELESAI' AND o.status_order <> 'SELESAI' THEN now() ELSE selesai_at END,
                siap_diambil_at = CASE WHEN perintah = 'SIAP' THEN coalesce(siap_diambil_at, now()) ELSE siap_diambil_at END,
                bukti_masuk_at = CASE WHEN n8n_ya(j ->> 'bukti_masuk') THEN coalesce(bukti_masuk_at, now()) ELSE bukti_masuk_at END,
                diingatkan_at = CASE WHEN n8n_ya(j ->> 'diingatkan') THEN now() ELSE diingatkan_at END,
                updated_at = now()
            WHERE id = o.id;

            IF nullif(btrim(coalesce(j ->> 'riwayat', '')), '') IS NOT NULL THEN
                INSERT INTO riwayat (subjek_type, subjek_id, waktu, jenis, teks, nada, created_at, updated_at)
                VALUES ('log_order', o.id, now(), 'CATATAN',
                    (j ->> 'riwayat') || coalesce(' oleh ' || n8n_nama_admin(nullif(j ->> 'riwayat_oleh', '')), ''), 'gray', now(), now());
            END IF;

            RETURN QUERY SELECT * FROM n8n_log_order WHERE id = o.id;
        END $$;

        -- JEDA: a new pause, or the running one for that customer changed
        CREATE OR REPLACE FUNCTION n8n_jeda(j jsonb) RETURNS SETOF n8n_jeda_bot LANGUAGE plpgsql AS $$
        DECLARE
            chat text := nullif(btrim(coalesce(j ->> 'chat_id', '')), '');
            v_nomor text := n8n_nomor(j ->> 'nomor');
            cust bigint;
            jid bigint;
        BEGIN
            IF chat IS NULL AND v_nomor IS NULL THEN RETURN; END IF;
            SELECT id INTO cust FROM crm_customer WHERE chat_id = chat OR (v_nomor IS NOT NULL AND nomor_wa = v_nomor)
                ORDER BY (chat_id = chat) DESC NULLS LAST LIMIT 1;
            SELECT id INTO jid FROM jeda_bot WHERE selesai_at IS NULL AND (chat_id = chat OR (v_nomor IS NOT NULL AND jeda_bot.nomor = v_nomor))
                ORDER BY id DESC LIMIT 1;
            IF jid IS NULL THEN
                INSERT INTO jeda_bot (crm_customer_id, nomor, chat_id, nama, sesi_wa, sejak, sampai, oleh, alasan, sudah_diberi_tahu, created_at, updated_at)
                VALUES (cust, v_nomor, coalesce(chat, v_nomor || '@c.us'), nullif(j ->> 'nama', ''), nullif(j ->> 'sesi_wa', ''),
                    coalesce(n8n_waktu(j ->> 'sejak'), now()), n8n_waktu(j ->> 'sampai'), n8n_nama_admin(j ->> 'oleh'),
                    nullif(j ->> 'alasan', ''), false, now(), now())
                RETURNING id INTO jid;
            ELSE
                UPDATE jeda_bot SET sampai = n8n_waktu(j ->> 'sampai'), oleh = coalesce(n8n_nama_admin(j ->> 'oleh'), oleh),
                    alasan = coalesce(nullif(j ->> 'alasan', ''), alasan), sudah_diberi_tahu = false, updated_at = now()
                WHERE id = jid;
            END IF;
            RETURN QUERY SELECT * FROM n8n_jeda_bot WHERE id = jid;
        END $$;

        -- LANJUT / expired pause: the running pause ends (kept as history, never deleted)
        CREATE OR REPLACE FUNCTION n8n_akhiri_jeda(j jsonb) RETURNS SETOF n8n_jeda_bot LANGUAGE plpgsql AS $$
        DECLARE
            chat text := nullif(btrim(coalesce(j ->> 'chat_id', '')), '');
            v_nomor text := n8n_nomor(j ->> 'nomor');
            habis boolean := n8n_ya(j ->> 'kedaluwarsa');
            ids bigint[];
        BEGIN
            WITH u AS (
                UPDATE jeda_bot SET
                    selesai_at = CASE WHEN habis THEN coalesce(least(sampai, now()), now()) ELSE now() END,
                    diakhiri_oleh = CASE WHEN habis THEN 'Sistem' ELSE coalesce(n8n_nama_admin(j ->> 'oleh'), 'Sistem') END,
                    catatan_selesai = nullif(j ->> 'catatan', ''),
                    updated_at = now()
                WHERE selesai_at IS NULL AND (chat_id = chat OR (v_nomor IS NOT NULL AND jeda_bot.nomor = v_nomor))
                RETURNING id)
            SELECT array_agg(id) INTO ids FROM u;
            RETURN QUERY SELECT * FROM n8n_jeda_bot WHERE id = ANY(coalesce(ids, ARRAY[]::bigint[]));
        END $$;

        -- PESAN: every message in and out. A wa_message_id already stored is skipped (no row back).
        CREATE OR REPLACE FUNCTION n8n_catat_pesan(j jsonb) RETURNS TABLE (id bigint) LANGUAGE plpgsql AS $$
        DECLARE
            chat text := nullif(btrim(coalesce(j ->> 'chat_id', '')), '');
            wa text := nullif(btrim(coalesce(j ->> 'wa_message_id', '')), '');
            pengirim text := upper(coalesce(nullif(j ->> 'pengirim', ''), 'ADIVA'));
            teks text := nullif(j ->> 'teks', '');
            cust bigint;
            baru bigint;
            bp bigint;
        BEGIN
            IF chat IS NULL THEN RETURN; END IF;
            IF wa IS NOT NULL AND EXISTS (SELECT 1 FROM pesan p WHERE p.wa_message_id = wa) THEN RETURN; END IF;
            SELECT c.id INTO cust FROM crm_customer c WHERE c.chat_id = chat;

            INSERT INTO pesan (crm_customer_id, chat_id, sesi_wa, waktu, pengirim, user_id, teks, gambar_produk_id, lampiran_url,
                lampiran_keterangan, tombol, jalur, status_kirim, wa_message_id, broadcast_id, meta, created_at, updated_at)
            VALUES (cust, chat, nullif(j ->> 'sesi_wa', ''), coalesce(n8n_waktu(j ->> 'waktu'), now()),
                CASE WHEN pengirim = ANY(ARRAY['CUSTOMER', 'ADIVA', 'ADMIN', 'SISTEM']) THEN pengirim ELSE 'ADIVA' END,
                CASE WHEN pengirim = 'ADMIN' THEN (SELECT u.id FROM users u WHERE u.nomor_wa = n8n_nomor(j ->> 'admin') LIMIT 1) END,
                teks, n8n_angka(j ->> 'gambar_produk_id'), nullif(j ->> 'lampiran_url', ''), nullif(j ->> 'lampiran_keterangan', ''),
                CASE WHEN jsonb_typeof(j -> 'tombol') = 'array' AND jsonb_array_length(j -> 'tombol') > 0 THEN j -> 'tombol' END,
                nullif(j ->> 'jalur', ''), CASE WHEN pengirim = 'CUSTOMER' THEN NULL ELSE coalesce(nullif(j ->> 'status_kirim', ''), 'TERKIRIM') END,
                wa, NULL, CASE WHEN jsonb_typeof(j -> 'meta') = 'object' THEN j -> 'meta' END, now(), now())
            RETURNING pesan.id INTO baru;

            -- the customer's first reply to a campaign sent in the last 3 days
            IF pengirim = 'CUSTOMER' THEN
                SELECT b.id INTO bp FROM broadcast_penerima b WHERE b.chat_id = chat AND b.status = 'TERKIRIM'
                    AND b.terkirim_at > now() - interval '3 days' AND b.dibalas_at IS NULL ORDER BY b.terkirim_at DESC LIMIT 1;
                IF bp IS NOT NULL THEN
                    UPDATE broadcast_penerima SET dibalas_at = now(), balasan = left(teks, 500),
                        minta_stop = minta_stop OR coalesce(teks, '') ~* '^\s*(stop|berhenti|unsubscribe)\s*[.!]*\s*$', updated_at = now()
                    WHERE broadcast_penerima.id = bp;
                    IF coalesce(teks, '') ~* '^\s*(stop|berhenti|unsubscribe)\s*[.!]*\s*$' THEN
                        UPDATE crm_customer SET status_optout = true, updated_at = now() WHERE crm_customer.id = cust;
                    END IF;
                END IF;
            END IF;

            RETURN QUERY SELECT baru;
        END $$;

        -- WAHA message.ack READ on a campaign message
        CREATE OR REPLACE FUNCTION n8n_pesan_dibaca(wa text) RETURNS TABLE (id bigint) LANGUAGE sql AS $$
            UPDATE broadcast_penerima SET dibaca_at = coalesce(dibaca_at, now()), updated_at = now()
            WHERE wa_message_id = wa RETURNING broadcast_penerima.id
        $$;

        -- NOTIFIKASI: every notice to the supervisor (App\Enums\JenisNotifikasi)
        CREATE OR REPLACE FUNCTION n8n_notifikasi(j jsonb) RETURNS TABLE (id bigint) LANGUAGE sql AS $$
            INSERT INTO notifikasi (waktu, jenis, kategori, penerima, judul, detail, crm_customer_id, log_order_id, created_at, updated_at)
            SELECT now(), x.jenis,
                CASE
                    WHEN x.jenis IN ('JEDA_CHAT', 'BUTUH_ADMIN', 'JEDA_KEDALUWARSA') THEN 'BUTUH_ADMIN'
                    WHEN x.jenis IN ('ORDER_BARU', 'INVOICE_GAGAL', 'ORDER_KARYAWAN', 'ORDER_DITUTUP', 'ORDER_DIBATALKAN', 'BUKTI_MASUK') THEN 'ORDER'
                    ELSE 'SISTEM' END,
                coalesce(nullif(j ->> 'penerima', ''), 'ADMIN_WA'), left(coalesce(nullif(j ->> 'judul', ''), x.jenis), 255),
                nullif(j ->> 'detail', ''),
                (SELECT c.id FROM crm_customer c WHERE c.chat_id = j ->> 'chat_id'),
                (SELECT o.id FROM log_order o WHERE o.order_id = j ->> 'order_id'),
                now(), now()
            FROM (SELECT upper(coalesce(nullif(j ->> 'jenis', ''), 'WORKFLOW_ERROR')) AS jenis) x
            RETURNING notifikasi.id
        $$;

        -- WORKFLOW_EVENT: a run, incident, connection check or report. One EKSEKUSI per n8n run.
        CREATE OR REPLACE FUNCTION n8n_event(j jsonb) RETURNS TABLE (id bigint) LANGUAGE plpgsql AS $$
        DECLARE
            wf bigint := (SELECT w.id FROM workflow w WHERE w.kode = j ->> 'workflow');
            jenis text := upper(coalesce(nullif(j ->> 'jenis', ''), 'EKSEKUSI'));
            eks text := nullif(j ->> 'n8n_execution_id', '');
            ada bigint;
        BEGIN
            IF jenis = 'EKSEKUSI' AND eks IS NOT NULL THEN
                SELECT e.id INTO ada FROM workflow_event e WHERE e.jenis = 'EKSEKUSI' AND e.n8n_execution_id = eks LIMIT 1;
            END IF;
            IF ada IS NOT NULL THEN
                UPDATE workflow_event SET status = coalesce(nullif(j ->> 'status', ''), status), judul = coalesce(nullif(j ->> 'judul', ''), judul),
                    detail = coalesce(nullif(j ->> 'detail', ''), detail), data = coalesce(data, '{}'::jsonb) || coalesce(n8n_json(j -> 'data'), '{}'::jsonb),
                    crm_customer_id = coalesce((SELECT c.id FROM crm_customer c WHERE c.chat_id = j ->> 'chat_id'), crm_customer_id),
                    updated_at = now()
                WHERE workflow_event.id = ada;
                RETURN QUERY SELECT ada;
                RETURN;
            END IF;
            RETURN QUERY
            INSERT INTO workflow_event (workflow_id, waktu, jenis, status, judul, detail, aksi, crm_customer_id, log_order_id,
                n8n_execution_id, durasi_ms, data, created_at, updated_at)
            VALUES (wf, now(), jenis, upper(coalesce(nullif(j ->> 'status', ''), 'BERHASIL')), left(coalesce(nullif(j ->> 'judul', ''), jenis), 255),
                nullif(j ->> 'detail', ''), nullif(j ->> 'aksi', ''),
                (SELECT c.id FROM crm_customer c WHERE c.chat_id = j ->> 'chat_id'),
                (SELECT o.id FROM log_order o WHERE o.order_id = j ->> 'order_id'),
                eks, n8n_angka(j ->> 'durasi_ms')::int, n8n_json(j -> 'data'), now(), now())
            RETURNING workflow_event.id;
        END $$;

        -- the invoice PDF n8n made (base64), for "Unduh PDF" and "Kirim ulang ke customer"
        CREATE OR REPLACE FUNCTION n8n_simpan_invoice_pdf(j jsonb) RETURNS TABLE (id bigint) LANGUAGE sql AS $$
            INSERT INTO invoice_pdf (log_order_id, nama_file, isi, created_at, updated_at)
            SELECT o.id, left(coalesce(nullif(j ->> 'nama_file', ''), 'invoice.pdf'), 120), decode(j ->> 'base64', 'base64'), now(), now()
            FROM log_order o WHERE o.order_id = j ->> 'order_id' AND nullif(j ->> 'base64', '') IS NOT NULL
            ON CONFLICT (log_order_id) DO UPDATE SET nama_file = excluded.nama_file, isi = excluded.isi, updated_at = now()
            RETURNING invoice_pdf.id
        $$;

        -- Arsip Data: the whole row is copied to arsip_data (once) …
        CREATE OR REPLACE FUNCTION n8n_simpan_arsip(j jsonb) RETURNS TABLE (id bigint) LANGUAGE sql AS $$
            INSERT INTO arsip_data (jenis, kunci, tanggal, data, diarsipkan, created_at, updated_at)
            SELECT j ->> 'jenis', j ->> 'kunci', n8n_waktu(j ->> 'tanggal'), n8n_json(j -> 'data'), coalesce(n8n_waktu(j ->> 'diarsipkan'), now()), now(), now()
            WHERE NOT EXISTS (SELECT 1 FROM arsip_data a WHERE a.jenis = j ->> 'jenis' AND a.kunci = j ->> 'kunci')
            RETURNING arsip_data.id
        $$;

        -- … and only an archived row is deleted (its messages and events keep, unlinked)
        CREATE OR REPLACE FUNCTION n8n_hapus_arsip(j jsonb) RETURNS TABLE (id bigint) LANGUAGE plpgsql AS $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM arsip_data a WHERE a.jenis = j ->> 'jenis' AND a.kunci = j ->> 'kunci') THEN RETURN; END IF;
            IF j ->> 'jenis' = 'ORDER' THEN
                RETURN QUERY DELETE FROM log_order o WHERE o.order_id = j ->> 'kunci' RETURNING o.id;
            ELSIF j ->> 'jenis' = 'CUSTOMER' THEN
                RETURN QUERY DELETE FROM crm_customer c WHERE c.chat_id = j ->> 'kunci'
                    AND NOT EXISTS (SELECT 1 FROM log_order o WHERE o.crm_customer_id = c.id) RETURNING c.id;
            END IF;
        END $$;

        -- the n8n database role only needs EXECUTE on these and SELECT on the views
        ALTER FUNCTION n8n_simpan_crm(jsonb, boolean) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_catat_order(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_ubah_order(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_jeda(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_akhiri_jeda(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_catat_pesan(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_pesan_dibaca(text) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_notifikasi(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_event(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_simpan_invoice_pdf(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_simpan_arsip(jsonb) SECURITY DEFINER SET search_path = public;
        ALTER FUNCTION n8n_hapus_arsip(jsonb) SECURITY DEFINER SET search_path = public;
        SQL;
};
