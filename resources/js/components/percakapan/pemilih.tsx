import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';
import { hapus, simpan } from '@/routes/percakapan/template';

export type TemplateBalasan = { id: number; teks: string };
export type FotoProduk = { id: number; judul: string };

/**
 * A small menu above a button by the reply box; closes on a click outside or Esc.
 * (The design has no picker, so this follows "Aksi lain" on Pelanggan.)
 */
function MenuAtas({
    tombol,
    children,
}: {
    tombol: (buka: boolean, ubah: () => void) => ReactNode;
    children: (tutup: () => void) => ReactNode;
}) {
    const [buka, setBuka] = useState(false);
    const wadah = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!buka) {
            return;
        }

        const tutup = (e: MouseEvent | KeyboardEvent) => {
            if (
                e instanceof KeyboardEvent
                    ? e.key === 'Escape'
                    : !wadah.current?.contains(e.target as Node)
            ) {
                setBuka(false);
            }
        };

        document.addEventListener('mousedown', tutup);
        document.addEventListener('keydown', tutup);

        return () => {
            document.removeEventListener('mousedown', tutup);
            document.removeEventListener('keydown', tutup);
        };
    }, [buka]);

    return (
        <div ref={wadah} className="relative shrink-0">
            {tombol(buka, () => setBuka(!buka))}
            {buka && (
                <div
                    role="menu"
                    // phones: across the screen, above the reply box
                    className="absolute bottom-12 left-0 z-10 flex max-h-[320px] w-80 flex-col overflow-auto rounded-control border border-line bg-surface py-1 motion-safe:animate-[muncul_120ms_ease-out] max-md:fixed max-md:inset-x-3 max-md:bottom-32 max-md:w-auto"
                >
                    {children(() => setBuka(false))}
                </div>
            )}
        </div>
    );
}

const item =
    'flex w-full cursor-pointer items-start gap-2 border-none bg-transparent px-3 py-2 text-left text-body text-ink hover:bg-subtle';

/**
 * "Template": saved replies ({nama} becomes the customer's first name), and the text
 * in the box can be saved as a new one.
 */
export function MenuTemplate({
    template,
    panggilan,
    teks,
    onPakai,
}: {
    template: TemplateBalasan[];
    panggilan: string;
    teks: string;
    onPakai: (isi: string) => void;
}) {
    return (
        <MenuAtas
            tombol={(buka, ubah) => (
                <button
                    type="button"
                    aria-haspopup="menu"
                    aria-expanded={buka}
                    onClick={ubah}
                    className="h-[42px] shrink-0 cursor-pointer rounded-card border border-line-strong bg-surface px-3 text-body font-semibold whitespace-nowrap text-ink"
                >
                    Template
                </button>
            )}
        >
            {(tutup) => (
                <>
                    {template.length === 0 && (
                        <span className="px-3 py-2 text-caption text-muted">
                            Belum ada template. Tulis balasan lalu simpan
                            sebagai template.
                        </span>
                    )}
                    {template.map((t) => (
                        <div key={t.id} className="flex items-start">
                            <button
                                type="button"
                                role="menuitem"
                                className={cn(item, 'line-clamp-3 grow')}
                                onClick={() => {
                                    onPakai(
                                        t.teks.split('{nama}').join(panggilan),
                                    );
                                    tutup();
                                }}
                            >
                                {t.teks}
                            </button>
                            <button
                                type="button"
                                aria-label="Hapus template"
                                onClick={() =>
                                    router.delete(hapus.url(t.id), {
                                        preserveScroll: true,
                                        preserveState: true,
                                        only: ['template'],
                                    })
                                }
                                className="flex size-8 shrink-0 cursor-pointer items-center justify-center border-none bg-transparent text-muted"
                            >
                                <Ikon nama="tutup" ukuran={14} />
                            </button>
                        </div>
                    ))}
                    <button
                        type="button"
                        role="menuitem"
                        aria-disabled={teks.trim() === ''}
                        className={cn(
                            item,
                            'border-t border-line-soft font-medium text-primary',
                            teks.trim() === '' && 'cursor-default text-faint',
                        )}
                        onClick={() =>
                            teks.trim() !== '' &&
                            router.post(
                                simpan.url(),
                                { teks },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['template'],
                                },
                            )
                        }
                    >
                        Simpan teks ini sebagai template
                    </button>
                </>
            )}
        </MenuAtas>
    );
}

/**
 * "Lampirkan gambar produk": a product photo (with a file) to send with the reply.
 */
export function MenuGambar({
    gambar,
    onPilih,
}: {
    gambar: FotoProduk[];
    onPilih: (foto: FotoProduk) => void;
}) {
    const [cari, setCari] = useState('');
    const tampil = gambar.filter((g) =>
        g.judul.toLowerCase().includes(cari.trim().toLowerCase()),
    );

    return (
        <MenuAtas
            tombol={(buka, ubah) => (
                <button
                    type="button"
                    aria-label="Lampirkan gambar produk"
                    aria-haspopup="menu"
                    aria-expanded={buka}
                    onClick={ubah}
                    className="flex size-[42px] shrink-0 cursor-pointer items-center justify-center rounded-card border border-line-strong bg-surface text-ink"
                >
                    <Ikon nama="gambar" ukuran={18} />
                </button>
            )}
        >
            {(tutup) => (
                <>
                    {gambar.length > 8 && (
                        <div className="px-2 pb-1">
                            <Input
                                aria-label="Cari foto produk"
                                placeholder="Cari foto"
                                value={cari}
                                onChange={(e) => setCari(e.target.value)}
                                className="h-8"
                            />
                        </div>
                    )}
                    {gambar.length === 0 && (
                        <span className="px-3 py-2 text-caption text-muted">
                            Belum ada foto produk. Unggah di Katalog → Gambar.
                        </span>
                    )}
                    {tampil.map((g) => (
                        <button
                            key={g.id}
                            type="button"
                            role="menuitem"
                            className={item}
                            onClick={() => {
                                onPilih(g);
                                tutup();
                            }}
                        >
                            <Ikon nama="gambar" ukuran={16} />
                            {g.judul}
                        </button>
                    ))}
                </>
            )}
        </MenuAtas>
    );
}
