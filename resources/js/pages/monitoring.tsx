import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, kelasTombol } from '@/components/ui/button';
import { Select } from '@/components/ui/form';
import { PageHeader } from '@/components/ui/page-header';
import { StatStrip } from '@/components/ui/stat-strip';
import type { Stat } from '@/components/ui/stat-strip';
import { Status } from '@/components/ui/status';
import { cn } from '@/lib/utils';
import { monitoring, otomasi, percakapan } from '@/routes';
import { qr, restart } from '@/routes/monitoring';

type Props = {
    rentang: string;
    pilihanRentang: { nilai: string; label: string }[];
    angka: Stat[];
    perJam: { jam: string; sukses: number; error: number }[];
    jalur: { label: string; n: number; diabaikan: boolean }[];
    insiden: {
        jam: string;
        judul: string;
        detail: string | null;
        perluTindakan: boolean;
        aksi: string | null;
    }[];
    integrasi: {
        nomor: {
            label: string;
            sesi: string;
            terhubung: boolean;
            status: string;
        }[];
        catatan: string;
        utama: { label: string; nilai: string };
        cadangan: { label: string; nilai: string; dipakai: boolean };
        ongkir: string;
        workflow: string;
    };
    jadwal: { label: string; nilai: string }[];
};

/** Monitoring.dc.html: bars up to 140px of the 150px chart. */
const TINGGI_BATANG = 140;

const kelasPanel =
    'flex flex-col gap-2.5 rounded-card border border-line bg-surface';

export default function Monitoring({
    rentang,
    pilihanRentang,
    angka,
    perJam,
    jalur,
    insiden,
    integrasi,
    jadwal,
}: Props) {
    const tertinggi = Math.max(1, ...perJam.map((j) => j.sukses + j.error));
    const jalurTerbanyak = Math.max(1, ...jalur.map((j) => j.n));
    const bolehWaha = usePage().props.panel?.akses.pengaturan ?? false;
    const [galatWaha, setGalatWaha] = useState<string | null>(null);

    // runs, incidents and connection checks follow n8n by themselves (not in the design)
    usePoll(15000);

    return (
        <AppLayout active="monitoring">
            <Head title="Monitoring sistem" />
            <PageHeader
                judul="Monitoring sistem"
                sub="Kesehatan workflow n8n, kegagalan yang sudah ditangani, dan status integrasi"
                aksi={
                    <Select
                        aria-label="Rentang waktu"
                        className="h-9 w-auto rounded-control px-2.5"
                        value={rentang}
                        onChange={(e) =>
                            router.get(
                                monitoring.url({
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
                }
            />
            <IsiHalaman className="gap-[18px] pb-6">
                <StatStrip stat={angka} rapat />

                <section className="grid grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] gap-5 max-lg:grid-cols-1">
                    <div className={cn(kelasPanel, 'px-5 py-4')}>
                        <div className="flex items-center gap-3.5">
                            <h2 className="m-0 grow text-panel font-semibold">
                                Eksekusi per jam
                            </h2>
                            <Legenda warna="bg-[#CBD5E1]">Sukses</Legenda>
                            <Legenda warna="bg-red-dot">Error</Legenda>
                        </div>
                        <div className="box-content flex h-[150px] items-end gap-1 border-b border-line">
                            {perJam.map((j) => {
                                const error = j.error
                                    ? Math.max(
                                          4,
                                          Math.round(
                                              (j.error / tertinggi) *
                                                  TINGGI_BATANG,
                                          ),
                                      )
                                    : 0;

                                return (
                                    <div
                                        key={j.jam}
                                        title={`${j.jam}.00 · ${j.sukses} sukses · ${j.error} error`}
                                        className="flex h-full grow flex-col justify-end gap-0.5"
                                    >
                                        {error > 0 && (
                                            <span
                                                className="rounded-t-[2px] bg-red-dot"
                                                style={{ height: error }}
                                            />
                                        )}
                                        <span
                                            className={cn(
                                                'bg-[#CBD5E1]',
                                                !error && 'rounded-t-[2px]',
                                            )}
                                            style={{
                                                height: Math.round(
                                                    (j.sukses / tertinggi) *
                                                        TINGGI_BATANG,
                                                ),
                                            }}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                        <div className="flex justify-between text-tag text-muted">
                            {['00', '06', '12', '18', '23'].map((jam) => (
                                <span key={jam}>{jam}</span>
                            ))}
                        </div>
                    </div>

                    <div className={cn(kelasPanel, 'px-5 py-4')}>
                        <h2 className="m-0 text-panel font-semibold">
                            Pesan masuk per jalur
                        </h2>
                        <div className="text-caption text-muted">
                            Setiap pesan dipilah dulu sebelum dijawab
                        </div>
                        {jalur.map((j) => (
                            <div
                                key={j.label}
                                title={`${j.label} · ${j.n}`}
                                className="grid grid-cols-[138px_minmax(0,1fr)_46px] items-center gap-2.5"
                            >
                                <span className="text-caption text-ink-2">
                                    {j.label}
                                </span>
                                <span className="flex h-2 rounded-[2px] bg-line-soft">
                                    <span
                                        className={cn(
                                            'h-2 rounded-[2px]',
                                            j.diabaikan
                                                ? 'bg-line-strong'
                                                : 'bg-primary',
                                        )}
                                        style={{
                                            width: `${Math.max(1.5, (j.n / jalurTerbanyak) * 100)}%`,
                                        }}
                                    />
                                </span>
                                <span className="text-right text-caption font-semibold tabular-nums">
                                    {j.n.toLocaleString('id-ID')}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="grid min-h-0 grow grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] gap-5 max-lg:grid-cols-1">
                    <div className="flex flex-col overflow-hidden rounded-card border border-line bg-surface">
                        <div className="flex items-center border-b border-line-softer px-[18px] py-3.5">
                            <h2 className="m-0 grow text-panel font-semibold">
                                Jaring pengaman
                            </h2>
                            <span className="text-caption text-muted">
                                Kegagalan yang ditangkap workflow hari ini
                            </span>
                        </div>
                        <div className="overflow-auto">
                            {insiden.map((e) => (
                                <div
                                    key={`${e.jam}-${e.judul}`}
                                    className="grid grid-cols-[50px_minmax(0,1fr)_118px] items-center gap-3 border-b border-line-softer px-[18px] py-[11px]"
                                >
                                    <span className="text-caption text-muted">
                                        {e.jam}
                                    </span>
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className="text-body font-semibold">
                                            {e.judul}
                                        </span>
                                        <span className="text-caption text-ink-3">
                                            {e.detail}
                                        </span>
                                    </span>
                                    <span className="flex flex-col items-end gap-1">
                                        <Status
                                            nada={
                                                e.perluTindakan
                                                    ? 'red'
                                                    : 'green'
                                            }
                                        >
                                            {e.perluTindakan
                                                ? 'Perlu tindakan'
                                                : 'Tertangani'}
                                        </Status>
                                        {e.aksi && (
                                            <a
                                                href={percakapan.url()}
                                                className="text-caption font-medium no-underline"
                                            >
                                                {e.aksi}
                                            </a>
                                        )}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <div className={cn(kelasPanel, 'px-[18px] py-4')}>
                            <h2 className="m-0 text-panel font-semibold">
                                Integrasi
                            </h2>
                            {integrasi.nomor.map((n) => (
                                <BarisIntegrasi
                                    key={n.sesi}
                                    titik={
                                        n.terhubung
                                            ? 'bg-green-dot'
                                            : 'bg-red-dot'
                                    }
                                    label={
                                        <>
                                            {n.label}{' '}
                                            <span className="text-muted">
                                                ({n.sesi})
                                            </span>
                                        </>
                                    }
                                >
                                    <span
                                        className={cn(
                                            'text-caption font-medium',
                                            n.terhubung
                                                ? 'text-green-text'
                                                : 'text-red-text',
                                        )}
                                    >
                                        {n.status}
                                    </span>
                                </BarisIntegrasi>
                            ))}
                            {/* the number that is down (or the main one): QR in a new tab, restart here; supervisors only */}
                            <div className="flex gap-2 pl-[18px]">
                                {bolehWaha ? (
                                    <a
                                        href={qr.url()}
                                        target="_blank"
                                        rel="noreferrer"
                                        className={kelasTombol(
                                            'secondary',
                                            'sm',
                                            false,
                                            'px-2.5 text-caption',
                                        )}
                                    >
                                        Lihat QR
                                    </a>
                                ) : (
                                    <Button
                                        ukuran="sm"
                                        className="px-2.5 text-caption"
                                        disabled
                                    >
                                        Lihat QR
                                    </Button>
                                )}
                                <Button
                                    ukuran="sm"
                                    className="px-2.5 text-caption"
                                    disabled={!bolehWaha}
                                    onClick={() =>
                                        router.post(
                                            restart.url(),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onError: (e) =>
                                                    setGalatWaha(
                                                        e.waha ?? null,
                                                    ),
                                                onSuccess: () =>
                                                    setGalatWaha(null),
                                            },
                                        )
                                    }
                                >
                                    Restart sesi
                                </Button>
                            </div>
                            {galatWaha && (
                                <span
                                    role="alert"
                                    className="pl-[18px] text-caption text-red-text"
                                >
                                    {galatWaha}
                                </span>
                            )}
                            <span className="pl-[18px] text-caption leading-[1.45] text-muted">
                                {integrasi.catatan}
                            </span>
                            <BarisIntegrasi
                                titik="bg-green-dot"
                                label={integrasi.utama.label}
                            >
                                <span className="text-caption text-ink-3">
                                    {integrasi.utama.nilai}
                                </span>
                            </BarisIntegrasi>
                            <BarisIntegrasi
                                titik={
                                    integrasi.cadangan.dipakai
                                        ? 'bg-amber-dot'
                                        : 'bg-green-dot'
                                }
                                label={integrasi.cadangan.label}
                            >
                                <span
                                    className={cn(
                                        integrasi.cadangan.dipakai
                                            ? 'text-caption font-medium text-amber-text'
                                            : 'text-caption text-ink-3',
                                    )}
                                >
                                    {integrasi.cadangan.nilai}
                                </span>
                            </BarisIntegrasi>
                            <BarisIntegrasi
                                titik="bg-green-dot"
                                label="Info pengiriman (ongkir COD)"
                            >
                                <span className="text-caption text-ink-3">
                                    {integrasi.ongkir}
                                </span>
                            </BarisIntegrasi>
                            <BarisIntegrasi
                                titik="bg-green-dot"
                                label="Workflow n8n"
                            >
                                <span className="text-caption text-ink-3">
                                    {integrasi.workflow}
                                </span>
                            </BarisIntegrasi>
                        </div>

                        <div className={cn(kelasPanel, 'px-[18px] py-4')}>
                            <div className="flex items-center gap-2.5">
                                <h2 className="m-0 grow text-panel font-semibold">
                                    Jadwal otomatis
                                </h2>
                                <Link
                                    href={otomasi.url()}
                                    className="text-caption font-medium no-underline"
                                >
                                    Otomasi
                                </Link>
                            </div>
                            {jadwal.map((j) => (
                                <div
                                    key={j.label}
                                    className="flex items-center gap-2.5 text-body"
                                >
                                    <span className="grow">{j.label}</span>
                                    <span className="whitespace-nowrap text-ink-3">
                                        {j.nilai}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            </IsiHalaman>
        </AppLayout>
    );
}

function Legenda({ warna, children }: { warna: string; children: ReactNode }) {
    return (
        <span className="flex items-center gap-1.5 text-caption text-ink-3">
            <span className={cn('size-2.5 rounded-[2px]', warna)} />
            {children}
        </span>
    );
}

type BarisIntegrasiProps = {
    titik: string;
    label: ReactNode;
    children: ReactNode;
};

function BarisIntegrasi({ titik, label, children }: BarisIntegrasiProps) {
    return (
        <div className="flex items-center gap-2.5">
            <span className={cn('size-1.5 rounded-full', titik)} />
            <span className="grow text-body">{label}</span>
            {children}
        </div>
    );
}
