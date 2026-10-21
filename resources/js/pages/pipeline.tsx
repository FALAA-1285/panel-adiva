import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { ButtonLink } from '@/components/ui/button';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { StatStrip } from '@/components/ui/stat-strip';
import type { Stat } from '@/components/ui/stat-strip';
import { cn } from '@/lib/utils';
import { pelanggan, percakapan, pipeline } from '@/routes';
import { baru } from '@/routes/broadcast';
import { pindah } from '@/routes/pipeline';

type Filter = { cari: string; tipe: string; skor: string; admin: boolean };

type Kartu = {
    id: number;
    tahap: string;
    nama: string;
    tipe: string;
    skor: string;
    minat: string;
    keterangan: string;
    tanda: { teks: string; nada: 'amber' | 'gray' | 'red' } | null;
};

type Kolom = {
    kode: string;
    label: string;
    definisi: string;
    n: number;
    kartu: Kartu[];
};

type Props = {
    filter: Filter;
    angka: Stat[];
    kolom: Kolom[];
};

const pilihanTipe = [
    ['', 'Semua'],
    ['RESELLER', 'Reseller'],
    ['RETAIL', 'Retail'],
    ['KARYAWAN', 'Karyawan'],
    ['BELUM_JELAS', 'Belum jelas'],
] as const;

const pilihanSkor = [
    ['', 'Semua'],
    ['HOT', 'HOT'],
    ['WARM', 'WARM'],
    ['COLD', 'COLD'],
] as const;

const warnaSkor: Record<string, string> = {
    HOT: 'text-red-text',
    WARM: 'text-amber-text',
    COLD: 'text-muted',
};

/** Pipeline.dc.html flagStyle: #B45309, #4B5563, #B91C1C. */
const warnaTanda = {
    amber: 'text-amber-text',
    gray: 'text-gray-text',
    red: 'text-red-text',
};

const query = (f: Filter, buka: string | null) => ({
    cari: f.cari || undefined,
    tipe: f.tipe || undefined,
    skor: f.skor || undefined,
    admin: f.admin ? 1 : undefined,
    buka: buka ?? undefined,
});

export default function Pipeline({ filter, angka, kolom }: Props) {
    const [isian, setIsian] = useState(filter);
    const [buka, setBuka] = useState<string | null>(null);
    const [dipilih, setDipilih] = useState<number | null>(null);
    const pertama = useRef(true);

    // search and chips reload the board, a moment after typing stops
    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(
            () =>
                router.get(
                    pipeline.url({ query: query(isian, buka) }),
                    {},
                    {
                        only: ['kolom'],
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            250,
        );

        return () => clearTimeout(tunda);
    }, [isian, buka]);

    const pindahkan = (id: number, arah: -1 | 1) =>
        router.patch(
            pindah.url(id),
            { arah },
            {
                only: ['kolom', 'angka'],
                preserveState: true,
                preserveScroll: true,
            },
        );

    return (
        <AppLayout active="pipeline" penuhLayar>
            <Head title="CRM Pipeline" />
            <PageHeader
                rapat
                judul="CRM Pipeline"
                sub="Tahap tiap customer diperbarui Adiva di setiap balasan. Pilih kartu untuk memindahkannya secara manual."
                aksi={
                    <>
                        <div
                            role="group"
                            aria-label="Tampilan"
                            className="flex gap-0.5 rounded-[9px] bg-line-softer p-[3px]"
                        >
                            <button
                                type="button"
                                aria-pressed="true"
                                className="h-[30px] shrink-0 rounded-nav border-none bg-surface px-3 text-body font-semibold whitespace-nowrap text-ink"
                            >
                                Kanban
                            </button>
                            <Link
                                href={pelanggan.url()}
                                className="flex h-[30px] shrink-0 items-center rounded-nav px-3 text-body font-semibold whitespace-nowrap text-ink-3 no-underline"
                            >
                                Tabel
                            </Link>
                        </div>
                        <ButtonLink
                            href={baru.url({ query: segmen(isian) })}
                            varian="primary"
                            className="border-0"
                        >
                            <Ikon nama="broadcast" ukuran={15} tebal={2} />
                            Broadcast ke segmen ini
                        </ButtonLink>
                    </>
                }
            />
            <IsiHalaman className="gap-3.5 pt-[18px] pb-[22px]">
                <StatStrip stat={angka} rapat />
                <section
                    aria-label="Filter"
                    className="flex flex-wrap items-center gap-2.5"
                >
                    <label className="box-border flex h-[38px] w-[260px] items-center gap-2 rounded-control border border-line-strong bg-surface px-3 text-muted max-md:w-full">
                        <Ikon nama="cari" tebal={2} />
                        <input
                            type="search"
                            aria-label="Cari customer"
                            placeholder="Cari nama, kota, produk"
                            className="grow border-none bg-transparent text-body text-ink outline-none"
                            value={isian.cari}
                            onChange={(e) =>
                                setIsian({ ...isian, cari: e.target.value })
                            }
                        />
                    </label>
                    <span className="pl-1.5 text-caption font-medium text-muted">
                        Tipe
                    </span>
                    <GrupChip
                        label="Tipe"
                        pilihan={pilihanTipe}
                        nilai={isian.tipe}
                        onUbah={(tipe) => setIsian({ ...isian, tipe })}
                    />
                    <span className="pl-2.5 text-caption font-medium text-muted">
                        Skor
                    </span>
                    <GrupChip
                        label="Skor"
                        pilihan={pilihanSkor}
                        nilai={isian.skor}
                        onUbah={(skor) => setIsian({ ...isian, skor })}
                    />
                    <button
                        type="button"
                        aria-pressed={isian.admin}
                        onClick={() =>
                            setIsian({ ...isian, admin: !isian.admin })
                        }
                        className={cn(
                            'h-[34px] cursor-pointer rounded-control border px-3 text-caption font-medium whitespace-nowrap',
                            isian.admin
                                ? 'border-primary bg-primary-soft text-primary-ink'
                                : 'border-line-strong bg-surface text-ink hover:bg-subtle',
                            'motion-safe:transition-colors',
                        )}
                    >
                        Butuh admin saja
                    </button>
                    <span className="grow" />
                </section>
                <section
                    aria-label="Papan pipeline"
                    className={cn(
                        'grid min-h-0 grow grid-cols-[repeat(8,minmax(0,1fr))] gap-2.5',
                        // narrower than the design's 2112px: the columns keep their width and
                        // the board scrolls sideways (one column per swipe on phones)
                        'max-[2112px]:grid-cols-[repeat(8,190px)] max-[2112px]:overflow-x-auto max-[2112px]:pb-1',
                        'max-md:snap-x max-md:snap-mandatory max-md:grid-cols-[repeat(8,85vw)] max-md:*:snap-start',
                    )}
                >
                    {kolom.map((k, i) => (
                        <KolomPipeline
                            key={k.kode}
                            kolom={k}
                            dipilih={dipilih}
                            pertama={i === 0}
                            terakhir={i === kolom.length - 1}
                            onPilih={(id) =>
                                setDipilih(dipilih === id ? null : id)
                            }
                            onPindah={pindahkan}
                            onLainnya={() => setBuka(k.kode)}
                        />
                    ))}
                </section>
            </IsiHalaman>
        </AppLayout>
    );
}

/** The chips' filter as the segment to broadcast to. */
function segmen(f: Filter) {
    return { tipe: f.tipe || undefined, skor: f.skor || undefined };
}

type GrupChipProps = {
    label: string;
    pilihan: readonly (readonly [string, string])[];
    nilai: string;
    onUbah: (nilai: string) => void;
};

function GrupChip({ label, pilihan, nilai, onUbah }: GrupChipProps) {
    return (
        <div
            role="group"
            aria-label={label}
            className="flex gap-0.5 rounded-control bg-line-softer p-[3px]"
        >
            {pilihan.map(([kode, teks]) => {
                const aktif = kode === nilai;

                return (
                    <button
                        key={kode}
                        type="button"
                        aria-pressed={aktif}
                        onClick={() => onUbah(kode)}
                        className={cn(
                            'h-7 cursor-pointer rounded-chip border-none px-2.5 text-caption whitespace-nowrap',
                            aktif
                                ? 'bg-surface font-semibold text-ink shadow-[0_0_0_1px_var(--color-line)]'
                                : 'bg-transparent font-medium text-ink-3',
                        )}
                    >
                        {teks}
                    </button>
                );
            })}
        </div>
    );
}

type KolomPipelineProps = {
    kolom: Kolom;
    dipilih: number | null;
    pertama: boolean;
    terakhir: boolean;
    onPilih: (id: number) => void;
    onPindah: (id: number, arah: -1 | 1) => void;
    onLainnya: () => void;
};

function KolomPipeline({
    kolom,
    dipilih,
    pertama,
    terakhir,
    onPilih,
    onPindah,
    onLainnya,
}: KolomPipelineProps) {
    const keluar = kolom.kode === 'TIDAK_MINAT';
    const sisa = kolom.n - kolom.kartu.length;

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col rounded-card border border-line',
                keluar ? 'bg-line-softer' : 'bg-subtle',
            )}
        >
            <div className="flex flex-col gap-1 border-b border-line px-3 pt-3 pb-2.5">
                <div className="flex items-center gap-1.5">
                    <span
                        className={cn(
                            'size-2 shrink-0 rounded-full',
                            keluar ? 'bg-faint' : 'bg-primary',
                        )}
                    />
                    <span className="grow text-body font-semibold">
                        {kolom.label}
                    </span>
                    <span className="text-caption font-medium whitespace-nowrap text-muted tabular-nums">
                        {kolom.n}
                    </span>
                </div>
                <span className="text-caption leading-[1.4] text-muted">
                    {kolom.definisi}
                </span>
            </div>
            <div className="flex min-h-0 grow flex-col gap-2 overflow-auto p-2.5">
                {kolom.kartu.map((k) => (
                    <KartuPipeline
                        key={k.id}
                        kartu={k}
                        terpilih={dipilih === k.id}
                        pertama={pertama}
                        terakhir={terakhir}
                        onPilih={() => onPilih(k.id)}
                        onPindah={(arah) => onPindah(k.id, arah)}
                    />
                ))}
                {sisa > 0 && (
                    <button
                        type="button"
                        onClick={onLainnya}
                        className="h-[30px] cursor-pointer rounded-control border-none bg-transparent text-caption font-medium text-primary"
                    >
                        Lihat {sisa} lainnya
                    </button>
                )}
            </div>
        </div>
    );
}

type KartuPipelineProps = {
    kartu: Kartu;
    terpilih: boolean;
    pertama: boolean;
    terakhir: boolean;
    onPilih: () => void;
    onPindah: (arah: -1 | 1) => void;
};

function KartuPipeline({
    kartu,
    terpilih,
    pertama,
    terakhir,
    onPilih,
    onPindah,
}: KartuPipelineProps) {
    return (
        <div
            className={cn(
                'flex flex-col rounded-control border bg-surface px-[11px] py-2.5',
                terpilih
                    ? 'border-primary shadow-[0_0_0_1px_var(--color-primary)]'
                    : 'border-line',
            )}
        >
            <button
                type="button"
                aria-expanded={terpilih}
                onClick={onPilih}
                className="m-0 flex w-full cursor-pointer flex-col gap-1.5 border-none bg-transparent p-0 text-left text-ink"
            >
                <span className="flex w-full items-center gap-1.5">
                    <span className="grow truncate text-body font-semibold">
                        {kartu.nama}
                    </span>
                    <span
                        className={cn(
                            'shrink-0 text-tag font-semibold tracking-[0.02em] whitespace-nowrap',
                            warnaSkor[kartu.skor],
                        )}
                    >
                        {kartu.skor}
                    </span>
                </span>
                <span className="text-caption text-muted">{kartu.tipe}</span>
                <span className="text-caption leading-[1.4] text-ink-2">
                    {kartu.minat}
                </span>
                <span className="text-caption text-muted">
                    {kartu.keterangan}
                </span>
                {kartu.tanda && (
                    <span
                        className={cn(
                            'self-start text-caption font-medium whitespace-nowrap',
                            warnaTanda[kartu.tanda.nada],
                        )}
                    >
                        {kartu.tanda.teks}
                    </span>
                )}
            </button>
            {terpilih && (
                <div className="flex flex-col gap-1.5 border-t border-line-soft pt-2">
                    <span className="text-caption text-muted">
                        Pindah tahap secara manual
                    </span>
                    <div className="flex gap-1.5">
                        <button
                            type="button"
                            disabled={pertama}
                            onClick={() => onPindah(-1)}
                            className="h-8 grow cursor-pointer rounded-control border border-line-strong bg-surface text-caption font-semibold text-ink"
                        >
                            ← Mundur
                        </button>
                        <button
                            type="button"
                            disabled={terakhir}
                            onClick={() => onPindah(1)}
                            className="h-8 grow cursor-pointer rounded-control border border-primary bg-primary text-caption font-semibold text-white"
                        >
                            Maju →
                        </button>
                    </div>
                    <div className="flex gap-1.5">
                        <Link
                            href={percakapan.url({
                                query: { pilih: kartu.id },
                            })}
                            className="box-content flex h-[30px] grow items-center justify-center rounded-control border border-line-strong text-caption font-semibold text-ink no-underline"
                        >
                            Chat
                        </Link>
                        <Link
                            href={pelanggan.url({ query: { pilih: kartu.id } })}
                            className="box-content flex h-[30px] grow items-center justify-center rounded-control border border-line-strong text-caption font-semibold text-ink no-underline"
                        >
                            Profil
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
}
