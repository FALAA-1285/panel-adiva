import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppLayout } from '@/components/ui/app-layout';
import { ButtonLink } from '@/components/ui/button';
import { Ikon } from '@/components/ui/ikon';
import { home, pelanggan, pesanan } from '@/routes';
import { detail as detailPesanan } from '@/routes/pesanan';

const teks: Record<number, { judul: string; isi: string }> = {
    403: {
        judul: 'Halaman ini bukan untuk peran Anda',
        isi: 'Minta supervisor membuka aksesnya di Tim & akses kalau Anda memang perlu.',
    },
    404: {
        judul: 'Halaman tidak ditemukan',
        isi: 'Tautannya mungkin sudah lama, atau pesanan dan customer yang dicari sudah diarsipkan. Coba cari lagi di bawah.',
    },
    500: {
        judul: 'Ada yang tidak beres di server',
        isi: 'Perubahan Anda belum tentu tersimpan. Muat ulang halaman, lalu coba sekali lagi.',
    },
    503: {
        judul: 'Panel sedang dirawat',
        isi: 'Biasanya hanya beberapa menit. Bot Adiva tetap membalas customer seperti biasa.',
    },
};

/**
 * A missing page, a refused page or a server error, inside the panel frame with a
 * way back and a search box (Audit: Laravel's bare "Not Found" left people stranded).
 */
export default function Galat({ status }: { status: number }) {
    const { panel } = usePage().props;
    const t = teks[status] ?? teks[500];
    const [cari, setCari] = useState('');

    const isi = (
        <div className="flex grow items-center justify-center px-4 py-12">
            <div className="flex w-full max-w-[480px] flex-col items-center gap-4 rounded-card border border-line bg-surface px-8 py-10 text-center shadow-kartu max-md:px-5">
                <span className="text-tag font-semibold tracking-[0.03em] text-muted uppercase">
                    Galat {status}
                </span>
                <h1 className="m-0 text-page leading-[1.2] font-semibold">
                    {t.judul}
                </h1>
                <p className="m-0 text-body leading-[1.55] text-ink-3">
                    {t.isi}
                </p>
                {status === 404 && panel && (
                    <form
                        className="flex h-10 w-full items-center gap-2 rounded-control border fokus-bingkai border-line-control px-3 text-muted"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const kata = cari.trim();

                            if (kata !== '') {
                                router.visit(
                                    /^ord-/i.test(kata)
                                        ? detailPesanan.url(kata.toUpperCase())
                                        : pelanggan.url({
                                              query: { cari: kata },
                                          }),
                                );
                            }
                        }}
                    >
                        <Ikon nama="cari" tebal={2} />
                        <input
                            aria-label="Cari customer atau nomor pesanan"
                            placeholder="Nama customer atau ORD-…"
                            value={cari}
                            onChange={(e) => setCari(e.target.value)}
                            className="grow border-none bg-transparent text-body text-ink outline-none"
                        />
                    </form>
                )}
                <div className="flex flex-wrap justify-center gap-2">
                    <ButtonLink href={home.url()} varian="primary">
                        Kembali ke Tugas
                    </ButtonLink>
                    {status === 404 && panel && (
                        <ButtonLink href={pesanan.url()}>
                            Buka Pesanan
                        </ButtonLink>
                    )}
                </div>
            </div>
        </div>
    );

    // signed in: the whole frame, so the menu is there to go anywhere else
    if (panel) {
        return (
            <AppLayout active="none">
                <Head title={t.judul} />
                {isi}
            </AppLayout>
        );
    }

    return (
        <div className="flex min-h-dvh flex-col bg-latar font-sans text-ink">
            <Head title={t.judul} />
            <Link
                href={home.url()}
                className="m-4 flex w-fit items-center gap-2 text-body font-semibold text-ink no-underline"
            >
                <span className="flex size-8 items-center justify-center rounded-[10px] bg-linear-135 from-brand-from to-brand text-caption font-bold text-white">
                    BB
                </span>
                Bang Bana
            </Link>
            {isi}
        </div>
    );
}
