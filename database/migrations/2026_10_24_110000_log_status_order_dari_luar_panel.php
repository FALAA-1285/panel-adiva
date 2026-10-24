<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An order's status also changes outside the panel's own code: the bot and the n8n
     * workflows (Batas Bayar, a revised order) write it through n8n_ubah_order. A trigger
     * sees every path, so that:
     * - Log aktivitas has a "Sistem" line for each change the panel did not make itself
     *   (PerintahPesanan::jalankan sets panel.tanpa_log_status for its own transaction,
     *   as it writes its own line);
     * - the notice that asked someone to move the order is done once it has moved, as
     *   LogOrder's Eloquent hook did only for the panel's own updates.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION panel_status_order_berubah() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF coalesce(current_setting('panel.tanpa_log_status', true), '') <> 'on' THEN
                INSERT INTO activity_log (waktu, user_id, pelaku, sumber, kategori, aksi, detail, subjek_type, subjek_id, data, created_at, updated_at)
                VALUES (now(), NULL, 'Sistem', 'SISTEM', 'PESANAN', 'Status order berubah',
                        NEW.order_id || ' · ' || OLD.status_order || ' → ' || NEW.status_order,
                        'log_order', NEW.id, jsonb_build_object('dari', OLD.status_order, 'ke', NEW.status_order), now(), now());
            END IF;

            UPDATE notifikasi
               SET selesai_at = now(), dibaca_at = coalesce(dibaca_at, now()), updated_at = now()
             WHERE log_order_id = NEW.id
               AND selesai_at IS NULL
               AND jenis = ANY (CASE OLD.status_order
                   WHEN 'MENUNGGU_KONFIRMASI' THEN ARRAY['ORDER_BARU']
                   WHEN 'DIACC' THEN ARRAY['BUKTI_MASUK']
                   WHEN 'KARYAWAN' THEN ARRAY['ORDER_KARYAWAN']
                   ELSE ARRAY[]::text[] END);

            RETURN NULL;
        END $$;

        CREATE TRIGGER log_order_status_berubah
            AFTER UPDATE OF status_order ON log_order
            FOR EACH ROW WHEN (OLD.status_order IS DISTINCT FROM NEW.status_order)
            EXECUTE FUNCTION panel_status_order_berubah();
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS log_order_status_berubah ON log_order; DROP FUNCTION IF EXISTS panel_status_order_berubah();');
    }
};
