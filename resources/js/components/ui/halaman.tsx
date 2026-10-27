import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

export type InfoHalaman = { sekarang: number; total: number; per: number };

/**
 * "1–25 dari 312" with previous and next, under a long list (Pesanan, Pelanggan).
 * Hidden when everything fits on one page.
 */
export function Halaman({
    halaman: h,
    onPindah,
    className,
}: {
    halaman: InfoHalaman;
    onPindah: (ke: number) => void;
    className?: string;
}) {
    const terakhir = Math.max(1, Math.ceil(h.total / h.per));

    if (h.total <= h.per) {
        return null;
    }

    const dari = (h.sekarang - 1) * h.per + 1;
    const sampai = Math.min(h.total, h.sekarang * h.per);
    const tombol =
        'flex size-9 cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface text-ink-2 hover:bg-subtle disabled:cursor-default disabled:opacity-40 max-md:size-11';

    return (
        <nav
            aria-label="Halaman daftar"
            className={cn(
                'flex items-center gap-2 border-t border-line-soft px-4 py-2.5',
                className,
            )}
        >
            <span className="grow text-caption text-muted tabular-nums">
                {dari.toLocaleString('id-ID')}–{sampai.toLocaleString('id-ID')}{' '}
                dari {h.total.toLocaleString('id-ID')}
            </span>
            <button
                type="button"
                aria-label="Halaman sebelumnya"
                disabled={h.sekarang <= 1}
                onClick={() => onPindah(h.sekarang - 1)}
                className={tombol}
            >
                <Ikon nama="kiri" ukuran={16} tebal={2} />
            </button>
            <span className="text-caption text-ink-3 tabular-nums">
                {h.sekarang} / {terakhir}
            </span>
            <button
                type="button"
                aria-label="Halaman berikutnya"
                disabled={h.sekarang >= terakhir}
                onClick={() => onPindah(h.sekarang + 1)}
                className={tombol}
            >
                <Ikon nama="kanan" ukuran={16} tebal={2} />
            </button>
        </nav>
    );
}
