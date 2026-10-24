import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Select } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { StatStrip } from '@/components/ui/stat-strip';
import type { Stat } from '@/components/ui/stat-strip';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { cn } from '@/lib/utils';
import {
    home,
    monitoring,
    otomasi,
    pelanggan as pelangganRute,
    pesanan,
    pipeline,
} from '@/routes';
import { produk as katalogProduk } from '@/routes/katalog';
import { kirimUlang } from '@/routes/percakapan';
import { aksi as aksiPesanan } from '@/routes/pesanan';
import { ekspor } from '@/routes/ringkasan';

type AksiTindakan = {
    label: string;
    href?: string;
    aksi?: string;
    order?: string;
    utama?: boolean;
    garis?: boolean;
    /** The chat line to send again ("Kirim ulang"). */
    kirimUlang?: number | null;
};

type Tindakan = {
    id: number;
    judul: string;
    nada: Nada;
    waktu: string;
    nama: string;
    info: string | null;
    kode?: string;
    isi: string | null;
    aksi: AksiTindakan[];
};

type Props = {
    tanggal: string;
    rentang: string;
    pilihanRentang: { nilai: string; label: string }[];
    angka: Stat[];
    tindakan: Tindakan[];
    statusAdiva: {
        label: string;
        nilai: string;
        nada?: Nada;
        href?: string;
    }[];
    otomatis: {
        sub: string;
        baris: { waktu: string; judul: string; isi: string }[];
    };
    omzet: {
        sub: string;
        hari: { label: string; nilai: number; order: number }[];
    };
    produk: {
        sub: string;
        baris: {
            nama: string;
            qty: number;
            nilai: string;
            delta: string | null;
            deltaNada: string | null;
        }[];
    };
    pelanggan: {
        sub: string;
        persen: number;
        baris: { label: string; n: number; nilai: string; warna: string }[];
    };
    hilang: {
        sub: string;
        jumlah: number;
        baris: {
            label: string;
            n: number;
            nilai: string;
            nada: Nada;
            tab: string;
        }[];
    };
    volume: {
        sub: string;
        hari: { label: string; chat: number; diteruskan: number }[];
    };
    tahap: {
        jumlah: number;
        baris: { label: string; n: number; keluar: boolean }[];
    };
    tipe: {
        jumlah: number;
        baris: { label: string; n: number; warna: string }[];
    };
    pesanan: {
        jumlah: number;
        baris: { label: string; n: number; nada: Nada; tab: string }[];
    };
};

const angka = (n: number) => n.toLocaleString('id-ID');

/** A header link on the right of a panel (Main.dc.html: 13px, 600, blue). */
function TautanPanel({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <Link href={href} className="text-body font-semibold no-underline">
            {children}
        </Link>
    );
}

export default function Ringkasan(props: Props) {
    const { tanggal, rentang, pilihanRentang, tahap, tipe } = props;
    const [tabAnalitik, setTabAnalitik] = useState<
        'semua' | 'tahap' | 'tipe' | 'pesanan'
    >('semua');

    // the figures follow what n8n writes, without reloading the page (not in the design)
    usePoll(15000);

    return (
        <AppLayout active="main">
            <Head title="Ringkasan" />
            <PageHeader
                judul="Ringkasan"
                sub={tanggal}
                aksi={
                    <>
                        <Select
                            aria-label="Rentang waktu"
                            className="h-9 w-[170px]"
                            value={rentang}
                            onChange={(e) =>
                                router.get(
                                    home.url({
                                        query: { rentang: e.target.value },
                                    }),
                                    {},
                                    { preserveScroll: true, replace: true },
                                )
                            }
                        >
                            {pilihanRentang.map((p) => (
                                <option key={p.nilai} value={p.nilai}>
                                    {p.label}
                                </option>
                            ))}
                        </Select>
                        <Button
                            onClick={() =>
                                window.location.assign(
                                    ekspor.url({ query: { rentang } }),
                                )
                            }
                        >
                            <Ikon nama="unduh" ukuran={15} tebal={2} />
                            Ekspor
                        </Button>
                    </>
                }
            />
            <IsiHalaman>
                <StatStrip stat={props.angka} />

                <div className="grid grid-cols-[minmax(0,1fr)_380px] items-stretch gap-5 max-xl:grid-cols-[minmax(0,1fr)_320px] max-lg:grid-cols-1">
                    <Panel
                        judul="Perlu tindakan"
                        sub="Sama dengan notifikasi yang dikirim ke WhatsApp supervisor"
                        aksi={
                            <span className="text-caption text-muted">
                                {props.tindakan.length} item
                            </span>
                        }
                        className="h-full flex flex-col"
                        isiClassName="p-0 grow flex flex-col justify-center"
                    >
                        {props.tindakan.length === 0 ? (
                            <div className="flex flex-col items-center justify-center p-8 text-center">
                                <div className="mb-2.5 flex size-12 items-center justify-center rounded-full bg-note-green-bg text-green-text">
                                    <Ikon nama="centang" ukuran={24} tebal={2.5} />
                                </div>
                                <span className="text-body font-semibold text-ink">
                                    Semua beres!
                                </span>
                                <span className="mt-1 max-w-[320px] text-caption leading-relaxed text-muted">
                                    Tidak ada tindakan yang tertunda saat ini. Semua pesanan, chat, dan bot Adiva berjalan lancar.
                                </span>
                            </div>
                        ) : (
                            props.tindakan.map((t, i) => (
                                <BarisTindakan
                                    key={t.id}
                                    tindakan={t}
                                    terakhir={i === props.tindakan.length - 1}
                                />
                            ))
                        )}
                    </Panel>

                    <div className="flex flex-col gap-5">
                        <Panel
                            judul="Status Adiva"
                            aksi={
                                <TautanPanel href={monitoring.url()}>
                                    Monitoring
                                </TautanPanel>
                            }
                            isiClassName="pt-1 pb-1.5"
                        >
                            {props.statusAdiva.map((s, i) => (
                                <div
                                    key={s.label}
                                    className={cn(
                                        'flex items-center gap-3 py-[9px]',
                                        i < props.statusAdiva.length - 1 &&
                                            'border-b border-line-soft',
                                    )}
                                >
                                    <span className="grow text-body">
                                        {s.label}
                                    </span>
                                    {s.href ? (
                                        <Link
                                            href={s.href}
                                            className="text-caption font-medium no-underline"
                                        >
                                            {s.nilai}
                                        </Link>
                                    ) : s.nada ? (
                                        <Status nada={s.nada}>{s.nilai}</Status>
                                    ) : (
                                        <span className="text-caption leading-[1.4] text-muted">
                                            {s.nilai}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </Panel>

                        <Panel
                            judul="Pesan otomatis"
                            sub={props.otomatis.sub}
                            aksi={
                                <TautanPanel href={otomasi.url()}>
                                    Otomasi
                                </TautanPanel>
                            }
                            isiClassName="pt-1 pb-1.5"
                        >
                            {props.otomatis.baris.map((b, i) => (
                                <div
                                    key={b.judul}
                                    className={cn(
                                        'grid grid-cols-[52px_minmax(0,1fr)] gap-2.5 py-[9px]',
                                        i < props.otomatis.baris.length - 1 &&
                                            'border-b border-line-soft',
                                    )}
                                >
                                    <span className="text-caption text-muted tabular-nums">
                                        {b.waktu}
                                    </span>
                                    <div className="flex flex-col gap-px">
                                        <span className="text-body">
                                            {b.judul}
                                        </span>
                                        <span className="text-caption text-muted">
                                            {b.isi}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </Panel>
                    </div>
                </div>

                <Panel
                    judul="Omzet · 30 hari"
                    sub={props.omzet.sub}
                    aksi={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-caption text-ink-3">
                            <Legenda warna="bg-primary">Omzet</Legenda>
                            <Legenda warna="bg-line-strong">
                                Jumlah order
                            </Legenda>
                        </div>
                    }
                    isiClassName="pt-3.5 pr-[18px] pb-2.5 pl-0 max-md:overflow-x-auto"
                >
                    <GrafikOmzet hari={props.omzet.hari} />
                </Panel>

                <div className="grid grid-cols-3 items-start gap-5 max-xl:grid-cols-2 max-md:grid-cols-1">
                    <Panel
                        judul="Produk terlaris"
                        sub={props.produk.sub}
                        aksi={
                            <TautanPanel href={katalogProduk.url()}>
                                Katalog
                            </TautanPanel>
                        }
                        isiClassName="pt-1.5 pb-3"
                    >
                        <div className="flex flex-col">
                            {props.produk.baris.length === 0 && (
                                <span className="px-2 py-3 text-body text-muted">
                                    Belum ada produk terjual.
                                </span>
                            )}
                            {props.produk.baris.map((p, i) => (
                                <div
                                    key={p.nama}
                                    className="box-content grid h-[36px] grid-cols-[16px_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-line-soft px-2 transition-colors hover:bg-subtle/60"
                                >
                                    <span className="text-caption text-faint tabular-nums">
                                        {i + 1}
                                    </span>
                                    <span className="flex min-w-0 flex-col">
                                        <span className="truncate text-body text-ink">
                                            {p.nama}
                                        </span>
                                        <span className="text-caption text-muted tabular-nums">
                                            {angka(p.qty)} pcs
                                        </span>
                                    </span>
                                    <span className="flex flex-col items-end">
                                        <span className="text-body font-semibold tabular-nums">
                                            {p.nilai}
                                        </span>
                                        {p.delta && (
                                            <span
                                                className={cn(
                                                    'text-caption tabular-nums',
                                                    p.deltaNada === 'green'
                                                        ? 'text-green-text'
                                                        : 'text-red-text',
                                                )}
                                            >
                                                {p.delta}
                                            </span>
                                        )}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel
                        judul="Pelanggan baru vs lama"
                        sub={props.pelanggan.sub}
                        aksi={
                            <TautanPanel href={pelangganRute.url()}>
                                Pelanggan
                            </TautanPanel>
                        }
                        isiClassName="pb-3.5"
                    >
                        <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-[3px]">
                            {props.pelanggan.baris.map((p) => (
                                <span
                                    key={p.label}
                                    style={{
                                        width: `${(
                                            (p.n /
                                                Math.max(
                                                    1,
                                                    props.pelanggan.baris.reduce(
                                                        (a, b) => a + b.n,
                                                        0,
                                                    ),
                                                )) *
                                            100
                                        ).toFixed(1)}%`,
                                        background: p.warna,
                                    }}
                                />
                            ))}
                        </div>
                        <div className="mt-2.5 flex flex-col">
                            {props.pelanggan.baris.map((p) => (
                                <div
                                    key={p.label}
                                    className="box-content grid h-[32px] grid-cols-[12px_minmax(0,1fr)_44px_auto] items-center gap-2.5 border-b border-line-soft px-2 transition-colors hover:bg-subtle/60"
                                >
                                    <span
                                        className="size-2.5 rounded-[2px]"
                                        style={{ background: p.warna }}
                                    />
                                    <span className="truncate text-body text-ink-2">
                                        {p.label}
                                    </span>
                                    <span className="text-right text-body font-medium tabular-nums">
                                        {angka(p.n)}
                                    </span>
                                    <span className="text-right text-caption text-muted tabular-nums">
                                        {p.nilai}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel
                        judul="Order yang hilang"
                        sub={props.hilang.sub}
                        aksi={
                            <TautanPanel
                                href={pesanan.url({ query: { tab: 'batal' } })}
                            >
                                Pesanan
                            </TautanPanel>
                        }
                        isiClassName="pt-1.5 pb-3"
                    >
                        <div className="flex flex-col">
                            {props.hilang.baris.length === 0 && (
                                <span className="px-2 py-3 text-body text-muted">
                                    Tidak ada order yang batal.
                                </span>
                            )}
                            {props.hilang.baris.map((h) => (
                                <Link
                                    key={h.label}
                                    href={pesanan.url({
                                        query: { tab: h.tab },
                                    })}
                                    className="box-content flex h-[36px] items-center gap-2.5 rounded-[6px] border-b border-line-soft px-2 text-ink no-underline transition-colors hover:bg-subtle/60"
                                >
                                    <span className="grow">
                                        <Status
                                            nada={h.nada}
                                            ink
                                            className="text-body"
                                        >
                                            {h.label}
                                        </Status>
                                    </span>
                                    <span className="text-caption text-muted tabular-nums">
                                        {h.nilai}
                                    </span>
                                    <span className="text-body font-semibold tabular-nums">
                                        {h.n}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </Panel>
                </div>

                <Panel
                    judul="Volume chat · 30 hari"
                    sub={props.volume.sub}
                    aksi={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-caption text-ink-3">
                            <Legenda warna="bg-primary">Chat masuk</Legenda>
                            <Legenda warna="bg-faint">
                                Diteruskan ke admin
                            </Legenda>
                        </div>
                    }
                    isiClassName="pt-3.5 pr-[18px] pb-2.5 pl-0 max-md:overflow-x-auto"
                >
                    <GrafikVolume hari={props.volume.hari} />
                </Panel>

                <div className="flex flex-col gap-3.5">
                    <div className="flex items-center justify-between gap-3 max-md:flex-wrap">
                        <div className="flex items-center gap-1.5 rounded-control border border-line bg-subtle p-1 text-caption font-medium max-md:w-full max-md:overflow-x-auto">
                            {(
                                [
                                    ['semua', 'Semua'],
                                    [
                                        'pesanan',
                                        `Pesanan aktif (${props.pesanan.jumlah})`,
                                    ],
                                    [
                                        'tahap',
                                        `Tahap customer (${angka(tahap.jumlah)})`,
                                    ],
                                    [
                                        'tipe',
                                        `Tipe customer (${angka(tipe.jumlah)})`,
                                    ],
                                ] as const
                            ).map(([k, label]) => (
                                <button
                                    key={k}
                                    type="button"
                                    onClick={() => setTabAnalitik(k)}
                                    className={cn(
                                        'cursor-pointer rounded-[6px] px-3 py-1 font-medium whitespace-nowrap transition-colors duration-150',
                                        tabAnalitik === k
                                            ? 'bg-surface font-semibold text-ink shadow-xs'
                                            : 'text-muted hover:text-ink',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        <span className="text-caption text-muted max-md:hidden">
                            Ringkasan analitik real-time
                        </span>
                    </div>

                    <div
                        className={cn(
                            'grid items-start gap-5',
                            tabAnalitik === 'semua'
                                ? 'grid-cols-3 max-xl:grid-cols-2 max-md:grid-cols-1'
                                : 'grid-cols-1',
                        )}
                    >
                        {(tabAnalitik === 'semua' ||
                            tabAnalitik === 'tahap') && (
                            <Panel
                                judul="Customer per tahap"
                                sub={`${angka(tahap.jumlah)} customer aktif · penilaian AI terakhir`}
                                aksi={
                                    <TautanPanel href={pipeline.url()}>
                                        Pipeline
                                    </TautanPanel>
                                }
                                isiClassName="pt-3 pb-3.5"
                            >
                                <div className="flex flex-col gap-1">
                                    {tahap.baris.map((t) => (
                                        <div
                                            key={t.label}
                                            className="grid h-[28px] grid-cols-[130px_minmax(0,1fr)_32px] items-center gap-3 rounded-[6px] px-2 transition-colors hover:bg-subtle/60"
                                        >
                                            <span className="truncate text-body text-ink-2">
                                                {t.label}
                                            </span>
                                            <span className="flex h-2 overflow-hidden rounded-[2px] bg-line-soft">
                                                <span
                                                    className={cn(
                                                        'h-2 rounded-[2px]',
                                                        t.keluar
                                                            ? 'bg-line-strong'
                                                            : 'bg-primary',
                                                    )}
                                                    style={{
                                                        width: `${Math.round((t.n / Math.max(1, ...tahap.baris.map((b) => b.n))) * 100)}%`,
                                                    }}
                                                />
                                            </span>
                                            <span className="text-right text-body font-medium tabular-nums">
                                                {t.n}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </Panel>
                        )}

                        {(tabAnalitik === 'semua' ||
                            tabAnalitik === 'tipe') && (
                            <Panel
                                judul="Tipe customer"
                                sub={`Dari penilaian Adiva · ${angka(tipe.jumlah)} customer`}
                                isiClassName="pb-3.5"
                            >
                                <div className="flex h-2.5 gap-0.5 overflow-hidden rounded-[3px]">
                                    {tipe.baris.map((t) => (
                                        <span
                                            key={t.label}
                                            style={{
                                                width: `${((t.n / Math.max(1, tipe.jumlah)) * 100).toFixed(1)}%`,
                                                background: t.warna,
                                            }}
                                        />
                                    ))}
                                </div>
                                <div className="mt-2.5 flex flex-col">
                                    {tipe.baris.map((t) => (
                                        <div
                                            key={t.label}
                                            className="box-content grid h-[32px] grid-cols-[12px_minmax(0,1fr)_44px_44px] items-center gap-2.5 border-b border-line-soft px-2 transition-colors hover:bg-subtle/60"
                                        >
                                            <span
                                                className="size-2.5 rounded-[2px]"
                                                style={{ background: t.warna }}
                                            />
                                            <span className="truncate text-body text-ink-2">
                                                {t.label}
                                            </span>
                                            <span className="text-right text-body font-medium">
                                                {angka(t.n)}
                                            </span>
                                            <span className="text-right text-caption text-muted">
                                                {Math.round(
                                                    (t.n /
                                                        Math.max(
                                                            1,
                                                            tipe.jumlah,
                                                        )) *
                                                        100,
                                                )}
                                                %
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </Panel>
                        )}

                        {(tabAnalitik === 'semua' ||
                            tabAnalitik === 'pesanan') && (
                            <Panel
                                judul="Status pesanan aktif"
                                sub={`${angka(props.pesanan.jumlah)} order belum selesai`}
                                aksi={
                                    <TautanPanel href={pesanan.url()}>
                                        Pesanan
                                    </TautanPanel>
                                }
                                isiClassName="pt-1.5 pb-3"
                            >
                                <div className="flex flex-col">
                                    {props.pesanan.baris.map((p) => (
                                        <Link
                                            key={p.label}
                                            href={pesanan.url({
                                                query: { tab: p.tab },
                                            })}
                                            className="box-content flex h-[36px] items-center gap-2.5 rounded-[6px] border-b border-line-soft px-2 text-ink no-underline transition-colors hover:bg-subtle/60"
                                        >
                                            <span className="grow">
                                                <Status
                                                    nada={p.nada}
                                                    ink
                                                    className="text-body"
                                                >
                                                    {p.label}
                                                </Status>
                                            </span>
                                            <span className="text-body font-semibold tabular-nums">
                                                {p.n}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            </Panel>
                        )}
                    </div>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function BarisTindakan({
    tindakan: t,
    terakhir,
}: {
    tindakan: Tindakan;
    terakhir: boolean;
}) {
    return (
        <div
            className={cn(
                'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-5 px-[18px] py-3.5 transition-colors hover:bg-subtle/50 max-md:grid-cols-1 max-md:gap-3',
                !terakhir && 'border-b border-line-soft',
            )}
        >
            <div className="flex min-w-0 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <Status nada={t.nada}>{t.judul}</Status>
                    <span className="text-caption text-muted">· {t.waktu}</span>
                </div>
                <div className="flex flex-wrap items-baseline gap-x-1.5">
                    <span className="text-panel font-semibold">{t.nama}</span>
                    {t.info && (
                        <span className="text-caption text-muted">
                            {t.info}
                        </span>
                    )}
                </div>
                {(t.kode || t.isi) && (
                    <div className="text-body leading-[1.45] text-ink-3">
                        {t.kode && (
                            <span className="font-mono text-caption font-medium whitespace-nowrap text-ink-2">
                                {t.kode}
                            </span>
                        )}
                        {t.kode && t.isi && ' · '}
                        {t.isi}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-1.5 max-md:flex-wrap">
                {t.aksi.map((a) => {
                    const varian = a.utama
                        ? 'primary'
                        : a.garis
                          ? 'secondary'
                          : 'ghost';

                    if (a.href) {
                        return (
                            <ButtonLink
                                key={a.label}
                                href={a.href}
                                varian={varian}
                                ukuran="sm"
                            >
                                {a.label}
                            </ButtonLink>
                        );
                    }

                    return (
                        <Button
                            key={a.label}
                            varian={varian}
                            ukuran="sm"
                            onClick={() => {
                                if (a.kirimUlang) {
                                    router.post(
                                        kirimUlang.url(a.kirimUlang),
                                        {},
                                        { preserveScroll: true },
                                    );
                                } else if (a.order && a.aksi) {
                                    router.post(
                                        aksiPesanan.url(a.order),
                                        { aksi: a.aksi },
                                        { preserveScroll: true },
                                    );
                                }
                            }}
                        >
                            {a.label}
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}

function Legenda({ warna, children }: { warna: string; children: ReactNode }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={cn('h-0.5 w-3.5', warna)} />
            {children}
        </span>
    );
}

/**
 * Main.dc.html chart: 1090×220, plot 36–1090 × 10–194, a point every 36px; it leaves
 * 18px free on its right at 1440px.
 */
const GRAFIK = {
    lebar: 1090,
    sisa: 18,
    tinggi: 220,
    kiri: 36,
    kanan: 1090,
    atas: 10,
    bawah: 194,
    langkah: 36,
};

/** 4 gridlines on a round step: 15,5 → 20, 387,5 → 500. */
function langkahBulat(nilai: number) {
    const pangkat = 10 ** Math.floor(Math.log10(Math.max(nilai, 1)));

    return (
        [1, 2, 2.5, 5, 10].map((k) => k * pangkat).find((k) => k >= nilai) ??
        10 * pangkat
    );
}

/** Catmull-Rom through the points, written as cubic Béziers (as in the design). */
function garis(titik: { x: number; y: number }[]) {
    const f = (n: number) => n.toFixed(1);
    let d = `M${f(titik[0].x)} ${f(titik[0].y)}`;

    for (let i = 0; i < titik.length - 1; i++) {
        const p0 = titik[i - 1] ?? titik[i];
        const p1 = titik[i];
        const p2 = titik[i + 1];
        const p3 = titik[i + 2] ?? p2;
        d += ` C${f(p1.x + (p2.x - p0.x) / 6)} ${f(p1.y + (p2.y - p0.y) / 6)} ${f(p2.x - (p3.x - p1.x) / 6)} ${f(p2.y - (p3.y - p1.y) / 6)} ${f(p2.x)} ${f(p2.y)}`;
    }

    return d;
}

/** 1250000 → "1,25 jt", 80000 → "80 rb" — tick labels have to stay narrow. */
function rupiahSumbu(n: number) {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toLocaleString('id-ID', { maximumFractionDigits: 2 })} jt`;
    }

    return n >= 1000 ? `${Math.round(n / 1000)} rb` : String(n);
}

/**
 * Omzet per day as a line, with the number of orders behind it as faint bars. The two
 * series have their own scale, so each keeps its own axis: rupiah on the left, orders
 * on the right.
 */
function GrafikOmzet({ hari }: { hari: Props['omzet']['hari'] }) {
    const [lebar, setLebar] = useState(GRAFIK.lebar);
    const ref = useRef<SVGSVGElement>(null);

    useEffect(() => {
        const wadah = ref.current?.parentElement;

        if (!wadah) {
            return;
        }

        const amati = new ResizeObserver(() => {
            const gaya = getComputedStyle(wadah);
            const isi =
                wadah.clientWidth -
                parseFloat(gaya.paddingLeft) -
                parseFloat(gaya.paddingRight);

            setLebar(Math.max(GRAFIK.lebar, Math.floor(isi - GRAFIK.sisa)));
        });

        amati.observe(wadah);

        return () => amati.disconnect();
    }, []);

    const langkah = langkahBulat(Math.max(...hari.map((h) => h.nilai), 1) / 4);
    const puncak = langkah * 4;
    const langkahOrder = langkahBulat(
        Math.max(...hari.map((h) => h.order), 1) / 4,
    );
    const puncakOrder = langkahOrder * 4;
    const y = (v: number) =>
        GRAFIK.bawah - (v / puncak) * (GRAFIK.bawah - GRAFIK.atas);
    const yOrder = (v: number) =>
        GRAFIK.bawah - (v / puncakOrder) * (GRAFIK.bawah - GRAFIK.atas);
    const regang = (lebar - GRAFIK.kiri) / (GRAFIK.kanan - GRAFIK.kiri);
    const x = (i: number) => GRAFIK.kiri + i * GRAFIK.langkah * regang;
    const lebarBatang = Math.max(4, GRAFIK.langkah * regang * 0.42);
    const titik = hari.map((h, i) => ({ x: x(i), y: y(h.nilai) }));
    const akhir = titik[titik.length - 1];

    return (
        <svg
            ref={ref}
            width={lebar}
            height={GRAFIK.tinggi}
            viewBox={`0 0 ${lebar} ${GRAFIK.tinggi}`}
            role="img"
            aria-label="Grafik omzet dan jumlah order per hari, 30 hari"
            className="inline align-baseline max-[1440px]:h-auto max-[1440px]:w-full max-md:w-[720px] max-md:max-w-none"
            overflow="visible"
        >
            {[0, 1, 2, 3, 4].map((k) => (
                <g key={k}>
                    <line
                        x1={GRAFIK.kiri}
                        y1={y(k * langkah).toFixed(1)}
                        x2={lebar}
                        y2={y(k * langkah).toFixed(1)}
                        stroke="var(--color-line-soft)"
                        strokeWidth="1"
                    />
                    <text
                        x="30"
                        y={(y(k * langkah) + 4).toFixed(1)}
                        textAnchor="end"
                        fontSize="11"
                        fill="var(--color-muted)"
                        fontFamily="Geist, sans-serif"
                    >
                        {rupiahSumbu(k * langkah)}
                    </text>
                    <text
                        x={lebar + 6}
                        y={(y(k * langkah) + 4).toFixed(1)}
                        textAnchor="start"
                        fontSize="11"
                        fill="var(--color-faint)"
                        fontFamily="Geist, sans-serif"
                    >
                        {angka(k * langkahOrder)}
                    </text>
                </g>
            ))}
            {hari.map((h, i) => (
                <rect
                    key={`batang-${h.label}`}
                    x={(x(i) - lebarBatang / 2).toFixed(1)}
                    y={yOrder(h.order).toFixed(1)}
                    width={lebarBatang.toFixed(1)}
                    height={Math.max(0, GRAFIK.bawah - yOrder(h.order)).toFixed(
                        1,
                    )}
                    rx="2"
                    fill="var(--color-line-softer)"
                />
            ))}
            {hari.map((h, i) =>
                i % 3 === 0 ? (
                    <text
                        key={h.label}
                        x={x(i).toFixed(1)}
                        y="214"
                        textAnchor="middle"
                        fontSize="11"
                        fill="var(--color-muted)"
                        fontFamily="Geist, sans-serif"
                    >
                        {h.label}
                    </text>
                ) : null,
            )}
            <path
                d={garis(titik)}
                fill="none"
                stroke="var(--color-primary)"
                strokeWidth="2"
                strokeLinecap="round"
            />
            <circle
                cx={akhir.x.toFixed(1)}
                cy={akhir.y.toFixed(1)}
                r="3.5"
                fill="var(--color-surface)"
                stroke="var(--color-primary)"
                strokeWidth="2"
            />
        </svg>
    );
}

function GrafikVolume({ hari }: { hari: Props['volume']['hari'] }) {
    // wider than the design's 1440px the chart stretches with its panel: the days
    // spread out, the text keeps its size
    const [lebar, setLebar] = useState(GRAFIK.lebar);
    const ref = useRef<SVGSVGElement>(null);

    useEffect(() => {
        const wadah = ref.current?.parentElement;

        if (!wadah) {
            return;
        }

        const amati = new ResizeObserver(() => {
            const gaya = getComputedStyle(wadah);
            const isi =
                wadah.clientWidth -
                parseFloat(gaya.paddingLeft) -
                parseFloat(gaya.paddingRight);

            setLebar(Math.max(GRAFIK.lebar, Math.floor(isi - GRAFIK.sisa)));
        });

        amati.observe(wadah);

        return () => amati.disconnect();
    }, []);

    const langkah = langkahBulat(Math.max(...hari.map((h) => h.chat)) / 4);
    const puncak = langkah * 4;
    const y = (v: number) =>
        GRAFIK.bawah - (v / puncak) * (GRAFIK.bawah - GRAFIK.atas);
    const regang = (lebar - GRAFIK.kiri) / (GRAFIK.kanan - GRAFIK.kiri);
    const x = (i: number) => GRAFIK.kiri + i * GRAFIK.langkah * regang;
    const chat = hari.map((h, i) => ({ x: x(i), y: y(h.chat) }));
    const diteruskan = hari.map((h, i) => ({ x: x(i), y: y(h.diteruskan) }));
    const akhir = chat[chat.length - 1];

    return (
        <svg
            ref={ref}
            width={lebar}
            height={GRAFIK.tinggi}
            viewBox={`0 0 ${lebar} ${GRAFIK.tinggi}`}
            role="img"
            aria-label="Grafik chat masuk dan diteruskan ke admin, 30 hari"
            // inline like the design (Tailwind makes svg a block): the line box adds
            // the room under the chart; wider tick labels may pass the left edge
            // narrower than the design: the chart scales down; phones scroll it sideways
            className="inline align-baseline max-[1440px]:h-auto max-[1440px]:w-full max-md:w-[720px] max-md:max-w-none"
            overflow="visible"
        >
            {[0, 1, 2, 3, 4].map((k) => (
                <g key={k}>
                    <line
                        x1={GRAFIK.kiri}
                        y1={y(k * langkah).toFixed(1)}
                        x2={lebar}
                        y2={y(k * langkah).toFixed(1)}
                        stroke="var(--color-line-soft)"
                        strokeWidth="1"
                    />
                    <text
                        x="26"
                        y={(y(k * langkah) + 4).toFixed(1)}
                        textAnchor="end"
                        fontSize="11"
                        fill="var(--color-muted)"
                        fontFamily="Geist, sans-serif"
                    >
                        {angka(k * langkah)}
                    </text>
                </g>
            ))}
            {hari.map((h, i) =>
                i % 3 === 0 ? (
                    <text
                        key={h.label}
                        x={x(i).toFixed(1)}
                        y="214"
                        textAnchor="middle"
                        fontSize="11"
                        fill="var(--color-muted)"
                        fontFamily="Geist, sans-serif"
                    >
                        {h.label}
                    </text>
                ) : null,
            )}
            <path
                d={garis(diteruskan)}
                fill="none"
                stroke="var(--color-faint)"
                strokeWidth="1.75"
                strokeLinecap="round"
            />
            <path
                d={garis(chat)}
                fill="none"
                stroke="var(--color-primary)"
                strokeWidth="2"
                strokeLinecap="round"
            />
            <circle
                cx={akhir.x.toFixed(1)}
                cy={akhir.y.toFixed(1)}
                r="3.5"
                fill="var(--color-surface)"
                stroke="var(--color-primary)"
                strokeWidth="2"
            />
        </svg>
    );
}
