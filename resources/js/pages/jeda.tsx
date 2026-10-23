import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { Input, Segmented, Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { StatStrip } from '@/components/ui/stat-strip';
import { cn } from '@/lib/utils';
import { percakapan } from '@/routes';
import { lanjut, simpan } from '@/routes/jeda';

type Aktif = {
    id: number;
    nama: string;
    nomor: string | null;
    /** short label of the bot number the chat came in on ("CS 2") */
    sesi: string | null;
    sejak: string;
    sampai: string;
    sisa: string;
    oleh: string | null;
    alasan: string | null;
    kabar: string;
};

type Riwayat = {
    id: number;
    nama: string;
    waktu: string;
    durasi: string;
    alasan: string | null;
    catatan: string | null;
    oleh: string | null;
};

type Props = {
    ringkasan: { label: string; nilai: string; meta: string }[];
    aktif: Aktif[];
    riwayat: Riwayat[];
    terakhir: { nama: string; jam: string } | null;
};

const DURASI = ['30 menit', '1 jam', '2 jam', '1 hari', 'Tanpa batas'] as const;

// narrow screens: the columns keep their width and the card scrolls sideways
const KOLOM_AKTIF =
    // the second column holds "Kemarin → ∞" over a chip like "tanpa batas · diingatkan 09.00"
    'grid grid-cols-[minmax(0,1.1fr)_184px_56px_minmax(0,1.35fr)_128px] gap-3 max-xl:min-w-[780px]';
const KOLOM_RIWAYAT =
    'grid grid-cols-[minmax(0,1fr)_150px_70px_minmax(0,1.8fr)_64px] gap-2.5 max-xl:min-w-[680px]';

const kelasIsian = 'h-10 rounded-[9px] px-3 text-body';

export default function Jeda({ ringkasan, aktif, riwayat, terakhir }: Props) {
    const form = useForm({
        customer: '',
        durasi: '2 jam' as (typeof DURASI)[number],
        alasan: '',
    });
    const [catatan, setCatatan] = useState('');

    // pauses set or ended on WhatsApp, and expiring ones, show up by themselves
    usePoll(10000, {
        only: ['ringkasan', 'aktif', 'riwayat', 'terakhir', 'panel'],
    });

    const jeda = () =>
        form.post(simpan.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('customer', 'alasan'),
        });

    const lanjutkan = (id: number) =>
        router.post(
            lanjut.url(id),
            { catatan },
            { preserveScroll: true, onSuccess: () => setCatatan('') },
        );

    return (
        <AppLayout active="jeda">
            <Head title="Ambil alih chat" />
            <PageHeader
                rapat
                judul="Ambil alih chat"
                sub="Bot diam untuk customer tertentu selama admin membalas sendiri — sama dengan perintah JEDA dan LANJUT di WhatsApp"
            />
            <IsiHalaman className="gap-[18px] pt-[22px] pb-6">
                <StatStrip rapat stat={ringkasan} />

                <section className="grid min-h-0 grow grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-5 max-lg:grid-cols-1">
                    <div className="flex min-w-0 flex-col gap-[18px]">
                        <div className="overflow-hidden rounded-card border border-line bg-surface max-xl:overflow-x-auto">
                            <KepalaKartu
                                judul="Sedang dijeda"
                                keterangan="Bot diam untuk customer ini; customer dikabari sekali"
                            />
                            <div
                                className={cn(
                                    KOLOM_AKTIF,
                                    'border-b border-line-softer bg-subtle px-[18px] py-2.5 text-caption font-medium text-muted',
                                )}
                            >
                                <span>Customer</span>
                                <span>Sejak → sampai</span>
                                <span>Oleh</span>
                                <span>Alasan</span>
                                <span />
                            </div>
                            {aktif.map((r) => (
                                <div
                                    key={r.id}
                                    className={cn(
                                        KOLOM_AKTIF,
                                        'items-center border-b border-line-softer px-[18px] py-3',
                                    )}
                                >
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className="truncate text-body font-semibold">
                                            {r.nama}
                                        </span>
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <span className="truncate font-mono text-tag text-muted">
                                                {r.nomor ?? '—'}
                                            </span>
                                            {r.sesi && (
                                                <span className="shrink-0 rounded-tag bg-line-softer px-1.5 text-tag font-medium whitespace-nowrap text-gray-text">
                                                    {r.sesi}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                    <span className="flex min-w-0 flex-col items-start gap-1">
                                        <span className="text-caption font-semibold whitespace-nowrap">
                                            {r.sejak} → {r.sampai}
                                        </span>
                                        {/* a chip, so the ones about to expire stand out when scanning */}
                                        <span
                                            className={cn(
                                                'max-w-full truncate rounded-tag px-1.5 py-px text-tag font-medium',
                                                r.sampai === '∞'
                                                    ? 'bg-line-softer text-gray-text'
                                                    : 'bg-note-amber-bg text-amber-text',
                                            )}
                                        >
                                            {r.sisa}
                                        </span>
                                    </span>
                                    <span className="text-caption">
                                        {r.oleh}
                                    </span>
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className="text-caption">
                                            {r.alasan}
                                        </span>
                                        <span className="text-tag text-muted">
                                            {r.kabar}
                                        </span>
                                    </span>
                                    <span className="flex justify-end gap-1.5">
                                        <Link
                                            href={percakapan.url()}
                                            aria-label="Buka chat"
                                            title="Buka chat"
                                            className="box-content flex size-[34px] shrink-0 items-center justify-center rounded-control border border-line-strong bg-surface text-ink"
                                        >
                                            <Ikon nama="whatsapp" tebal={2} />
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => lanjutkan(r.id)}
                                            className="h-[34px] cursor-pointer rounded-control border border-line-strong bg-surface px-3 text-caption font-semibold whitespace-nowrap text-ink hover:bg-subtle motion-safe:transition-colors"
                                        >
                                            Lanjutkan bot
                                        </button>
                                    </span>
                                </div>
                            ))}
                            {aktif.length === 0 && (
                                <div className="p-7 text-center text-body text-muted">
                                    Tidak ada customer yang dijeda. Adiva
                                    menjawab semua chat.
                                </div>
                            )}
                        </div>

                        <div className="flex min-h-0 grow flex-col overflow-hidden rounded-card border border-line bg-surface max-xl:overflow-x-auto">
                            <KepalaKartu
                                judul="Riwayat jeda"
                                keterangan="Durasi dihitung otomatis saat LANJUT"
                            />
                            <div
                                className={cn(
                                    KOLOM_RIWAYAT,
                                    'border-b border-line-softer bg-subtle px-[18px] py-2.5 text-caption font-medium text-muted',
                                )}
                            >
                                <span>Customer</span>
                                <span>Mulai → selesai</span>
                                <span>Durasi</span>
                                <span>Alasan · catatan serah terima</span>
                                <span>Oleh</span>
                            </div>
                            <div className="overflow-auto max-xl:overflow-visible">
                                {riwayat.map((h) => (
                                    <div
                                        key={h.id}
                                        className={cn(
                                            KOLOM_RIWAYAT,
                                            'items-start border-b border-line-softer px-[18px] py-[11px] text-caption',
                                        )}
                                    >
                                        <span className="font-semibold">
                                            {h.nama}
                                        </span>
                                        <span className="text-ink-2">
                                            {h.waktu}
                                        </span>
                                        <span className="font-semibold">
                                            {h.durasi}
                                        </span>
                                        <span className="flex flex-col gap-0.5">
                                            <span>{h.alasan}</span>
                                            <span className="text-ink-3">
                                                {h.catatan}
                                            </span>
                                        </span>
                                        <span>{h.oleh}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="flex min-w-0 flex-col gap-[18px]">
                        <Kartu className="gap-3">
                            <h2 className="m-0 text-panel font-semibold">
                                Jeda customer
                            </h2>
                            <Isian
                                label="Customer"
                                galat={form.errors.customer}
                            >
                                <Input
                                    type="text"
                                    placeholder="Cari nama atau nomor WA"
                                    className={kelasIsian}
                                    value={form.data.customer}
                                    onChange={(e) =>
                                        form.setData('customer', e.target.value)
                                    }
                                />
                            </Isian>
                            {terakhir && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('customer', terakhir.nama)
                                    }
                                    className="h-[34px] shrink-0 cursor-pointer rounded-control border border-line bg-surface px-[11px] text-left text-caption font-medium whitespace-nowrap text-ink-2"
                                >
                                    Pakai chat terakhir:{' '}
                                    <strong className="font-semibold text-ink">
                                        {terakhir.nama}
                                    </strong>{' '}
                                    · {terakhir.jam}
                                </button>
                            )}
                            <div className="flex flex-col gap-1.5">
                                <span className="text-caption font-semibold">
                                    Durasi
                                </span>
                                <Segmented
                                    className="flex-wrap"
                                    kelasPilihan="h-[34px]"
                                    pilihan={DURASI.map((d) => ({
                                        nilai: d,
                                        label: d,
                                    }))}
                                    nilai={form.data.durasi}
                                    onUbah={(d) => form.setData('durasi', d)}
                                />
                                <span className="text-caption text-muted">
                                    Tanpa batas = diingatkan ke supervisor tiap
                                    pagi 09.00. Selama dijeda, customer tidak
                                    ikut sapaan ulang.
                                </span>
                            </div>
                            <Isian label="Alasan" galat={form.errors.alasan}>
                                <Input
                                    type="text"
                                    placeholder="Mis. komplain, nego, order via telepon"
                                    className={kelasIsian}
                                    value={form.data.alasan}
                                    onChange={(e) =>
                                        form.setData('alasan', e.target.value)
                                    }
                                />
                            </Isian>
                            <Button
                                varian="primary"
                                className="h-10 text-body"
                                aria-disabled={form.processing}
                                onClick={jeda}
                            >
                                Jeda bot sekarang
                            </Button>
                            <div className="text-tag leading-[1.45] text-muted">
                                Jika waktu jeda habis, Adiva otomatis menjawab
                                lagi dan supervisor dikabari.
                            </div>
                        </Kartu>

                        <Kartu className="gap-2.5">
                            <h2 className="m-0 text-panel font-semibold">
                                Serah terima ke Adiva
                            </h2>
                            {/* the note is read by "Lanjutkan bot" in the table, which is
                                easy to miss when the two sit in different columns */}
                            <div className="text-caption text-ink-2">
                                Isi dulu di sini, lalu tekan{' '}
                                <strong className="font-semibold text-ink">
                                    Lanjutkan bot
                                </strong>{' '}
                                pada baris customernya di tabel sebelah.
                            </div>
                            <Isian label="Catatan untuk Adiva">
                                <Textarea
                                    rows={3}
                                    placeholder="Mis. kompensasi 2 bungkus di order berikutnya sudah disepakati"
                                    className="resize-none rounded-[9px] px-3 py-2 text-body leading-[1.4]"
                                    value={catatan}
                                    onChange={(e) => setCatatan(e.target.value)}
                                />
                            </Isian>
                            <div className="text-tag leading-[1.45] text-muted">
                                Catatan disimpan di profil customer dan dibaca
                                Adiva saat melanjutkan percakapan.
                            </div>
                        </Kartu>
                    </div>
                </section>
            </IsiHalaman>
        </AppLayout>
    );
}

function KepalaKartu({
    judul,
    keterangan,
}: {
    judul: string;
    keterangan: string;
}) {
    return (
        <div className="flex items-center border-b border-line-softer px-[18px] py-3.5">
            <h2 className="m-0 grow text-panel font-semibold">{judul}</h2>
            <span className="text-caption text-muted">{keterangan}</span>
        </div>
    );
}

function Kartu({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex flex-col rounded-card border border-line bg-surface px-[18px] py-4',
                className,
            )}
        >
            {children}
        </div>
    );
}

function Isian({
    label,
    galat,
    children,
}: {
    label: string;
    galat?: string;
    children: ReactNode;
}) {
    return (
        <label className="flex flex-col gap-1.5">
            <span className="text-caption font-semibold">{label}</span>
            {children}
            {galat && (
                <span className="text-caption text-red-text">{galat}</span>
            )}
        </label>
    );
}
