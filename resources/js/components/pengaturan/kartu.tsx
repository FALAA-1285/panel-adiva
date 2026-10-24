import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type KartuPengaturanProps = {
    judul: string;
    /** Line under the title (AI & perilaku bot, Notifikasi WhatsApp, Arsip data). */
    sub?: string;
    /** CONFIG_TOKO key on the right of the title (Nomor WhatsApp bot). */
    kunci?: string;
    /** Button on the right of the title (Ongkir: "Uji cek ongkir"). */
    aksi?: ReactNode;
    /** Space between the rows: 14px on most cards, 12px on some. */
    rapat?: boolean;
    children: ReactNode;
};

/**
 * One group of settings (Settings.dc.html): white card, 18px 20px padding, a title,
 * then its fields in a column.
 */
export function KartuPengaturan({
    judul,
    sub,
    kunci,
    aksi,
    rapat = false,
    children,
}: KartuPengaturanProps) {
    const h2 = (
        <h2
            className={cn(
                'm-0 text-panel font-semibold',
                (aksi || kunci) && 'grow',
            )}
        >
            {judul}
        </h2>
    );

    return (
        <section
            className={cn(
                'flex flex-col rounded-card border border-line bg-surface px-5 py-[18px]',
                rapat ? 'gap-3' : 'gap-3.5',
            )}
        >
            {aksi ? (
                <div className="flex items-center gap-2.5">
                    {h2}
                    {aksi}
                </div>
            ) : kunci ? (
                <div className="flex items-baseline gap-2" title={kunci}>
                    {h2}
                </div>
            ) : sub ? (
                <div className="flex flex-col gap-[3px]">
                    {h2}
                    <span className="text-caption text-muted">{sub}</span>
                </div>
            ) : (
                h2
            )}
            {children}
        </section>
    );
}
