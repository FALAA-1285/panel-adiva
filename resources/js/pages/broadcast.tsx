import { Head, router, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { ButtonLink } from '@/components/ui/button';
import { Select } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { StatStrip } from '@/components/ui/stat-strip';
import type { Stat } from '@/components/ui/stat-strip';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { cocok, LAYAR } from '@/hooks/use-layar';
import { cn } from '@/lib/utils';
import { broadcast, percakapan } from '@/routes';
import { baru, ekspor, hapus, kirimUlang } from '@/routes/broadcast';

type Baris = {
    id: number;
    nama: string;
    segmen: string | null;
    target: string;
    status: { label: string; nada: Nada };
    progres: number | null;
    hasil: string;
    jadwal: string;
};

type Laporan = {
    id: number;
    nama: string;
    segmen: string | null;
    corong: { label: string; n: string; judul: string; lebar: number }[];
    catatan: string | null;
    dampak: { naik: number; hot: number; stop: number };
    balasan: { nama: string; jam: string; teks: string }[];
    bisaKirimUlang: boolean;
    draf: boolean;
};

type Props = {
    filter: { status: string };
    pilihanStatus: { nilai: string; label: string }[];
    angka: Stat[];
    aturan: { label: string; nilai: string }[];
    daftar: Baris[];
    laporan: Laporan | null;
};

/** Broadcast.dc.html: name · recipients, target, status, replies · orders, schedule. */
const kolom =
    'grid-cols-[minmax(0,1.7fr)_72px_118px_110px_100px] max-xl:min-w-[660px]';

export default function Broadcast({
    filter,
    pilihanStatus,
    angka,
    aturan,
    daftar,
    laporan,
}: Props) {
    const [status, setStatus] = useState(filter.status);
    const [dipilih, setDipilih] = useState(laporan?.id);

    // while a campaign is sending, its progress and replies follow by themselves
    const mengirim = daftar.some((b) => b.status.label === 'Mengirim');
    const { start, stop } = usePoll(
        5000,
        { only: ['angka', 'daftar', 'laporan', 'panel'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (mengirim) {
            start();
        } else {
            stop();
        }
    }, [mengirim, start, stop]);

    const saring = (nilai: string) => {
        setStatus(nilai);
        setDipilih(undefined);
        router.get(
            broadcast.url({ query: { status: nilai || undefined } }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const pilih = (id: number) => {
        setDipilih(id);
        router.get(
            broadcast.url({
                query: { status: status || undefined, pilih: id },
            }),
            {},
            {
                only: ['laporan'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                // below 1024px the report sits under the list
                onSuccess: () => {
                    if (!cocok(LAYAR.sedang)) {
                        document
                            .getElementById('laporan-kampanye')
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start',
                            });
                    }
                },
            },
        );
    };

    return (
        <AppLayout active="broadcast">
            <Head title="Broadcast" />
            <PageHeader
                rapat
                judul="Broadcast"
                sub="Kirim pesan ke kelompok customer lewat WhatsApp. Balasan mereka tetap dijawab Adiva."
                aksi={
                    <ButtonLink
                        href={baru.url()}
                        varian="primary"
                        className="border-0"
                    >
                        <Ikon nama="tambah" ukuran={15} tebal={2} />
                        Buat kampanye
                    </ButtonLink>
                }
            />
            <IsiHalaman className="gap-[18px] pt-[22px] pb-6">
                <StatStrip stat={angka} rapat />
                <section className="grid min-h-0 grow grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)] gap-5 max-lg:grid-cols-1">
                    <div className="flex min-w-0 flex-col gap-[18px]">
                        <div className="overflow-hidden rounded-card border border-line bg-surface max-xl:overflow-x-auto">
                            <div className="flex items-center gap-2 border-b border-line-softer px-[18px] py-3.5">
                                <h2 className="m-0 grow text-panel font-semibold">
                                    Kampanye
                                </h2>
                                <Select
                                    aria-label="Filter status"
                                    className="h-[34px] w-auto rounded-[9px] px-2 text-caption"
                                    value={status}
                                    onChange={(e) => saring(e.target.value)}
                                >
                                    <option value="">Semua status</option>
                                    {pilihanStatus.map((p) => (
                                        <option key={p.nilai} value={p.nilai}>
                                            {p.label}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                            <div
                                className={cn(
                                    'grid gap-2.5 border-b border-line-softer bg-subtle px-[18px] py-2.5 text-caption font-medium text-muted',
                                    kolom,
                                )}
                            >
                                <span>Kampanye · penerima</span>
                                <span className="text-right">Target</span>
                                <span>Status</span>
                                <span>Balas · order</span>
                                <span>Jadwal</span>
                            </div>
                            {daftar.map((b) => (
                                <BarisKampanye
                                    key={b.id}
                                    baris={b}
                                    terpilih={b.id === dipilih}
                                    onPilih={() => pilih(b.id)}
                                />
                            ))}
                        </div>
                        <div className="flex flex-col gap-2.5 rounded-card border border-line bg-surface px-[18px] py-4">
                            <div className="flex items-baseline gap-2">
                                <h2 className="m-0 text-panel font-semibold">
                                    Aturan kirim aman
                                </h2>
                                <span className="text-caption text-muted">
                                    berlaku untuk semua kampanye
                                </span>
                            </div>
                            <div className="grid grid-cols-3 gap-x-5 gap-y-3.5 max-md:grid-cols-2">
                                {aturan.map((a) => (
                                    <div
                                        key={a.label}
                                        className="flex flex-col gap-0.5"
                                    >
                                        <span className="text-caption text-muted">
                                            {a.label}
                                        </span>
                                        <span className="text-body font-medium">
                                            {a.nilai}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                    {laporan && <LaporanKampanye laporan={laporan} />}
                </section>
            </IsiHalaman>
        </AppLayout>
    );
}

type BarisKampanyeProps = {
    baris: Baris;
    terpilih: boolean;
    onPilih: () => void;
};

function BarisKampanye({ baris, terpilih, onPilih }: BarisKampanyeProps) {
    return (
        <button
            type="button"
            aria-pressed={terpilih}
            onClick={onPilih}
            className={cn(
                'grid w-full cursor-pointer items-center gap-2.5 border-0 border-b border-line-softer px-[18px] py-3 text-ink',
                kolom,
                terpilih
                    ? 'bg-selected shadow-[inset_2px_0_0_var(--color-primary)]'
                    : 'bg-surface hover:bg-subtle',
                'motion-safe:transition-colors motion-safe:duration-150',
            )}
        >
            <span className="flex min-w-0 flex-col gap-[3px] text-left">
                <span className="text-body font-semibold">{baris.nama}</span>
                <span className="truncate text-caption text-muted">
                    {baris.segmen}
                </span>
            </span>
            <span className="text-right text-body font-semibold">
                {baris.target}
            </span>
            <span className="flex flex-col items-start gap-[5px]">
                <Status nada={baris.status.nada}>{baris.status.label}</Status>
                {baris.progres !== null && (
                    <span className="flex h-[3px] w-[100px] rounded-[2px] bg-line">
                        <span
                            className="h-[3px] rounded-[2px] bg-primary"
                            style={{ width: `${baris.progres}%` }}
                        />
                    </span>
                )}
            </span>
            <span className="text-left text-caption">{baris.hasil}</span>
            <span className="text-left text-caption text-ink-3">
                {baris.jadwal}
            </span>
        </button>
    );
}

function LaporanKampanye({ laporan }: { laporan: Laporan }) {
    const tombol =
        'h-9 grow cursor-pointer rounded-control border border-line-strong bg-surface text-caption font-semibold text-ink';

    return (
        <aside
            aria-label="Laporan kampanye"
            id="laporan-kampanye"
            className="flex min-w-0 scroll-mt-4 flex-col gap-3.5 self-start overflow-auto rounded-card border border-line bg-surface px-5 py-[18px]"
        >
            <div className="flex flex-col gap-1">
                <div className="text-caption font-medium text-muted">
                    Laporan kampanye
                </div>
                <h2 className="m-0 text-judul font-semibold tracking-[-0.01em]">
                    {laporan.nama}
                </h2>
                <div className="text-caption text-muted">{laporan.segmen}</div>
            </div>
            <div className="flex flex-col gap-[9px]">
                {laporan.corong.map((c, i) => (
                    <div
                        key={c.label}
                        title={c.judul}
                        className="grid grid-cols-[74px_minmax(0,1fr)_64px] items-center gap-2.5"
                    >
                        <span className="text-caption text-ink-2">
                            {c.label}
                        </span>
                        <span className="flex h-2 rounded-[2px] bg-line-soft">
                            <span
                                className={cn(
                                    'h-2 rounded-[2px]',
                                    i === 0 ? 'bg-line-strong' : 'bg-primary',
                                )}
                                style={{ width: `${c.lebar}%` }}
                            />
                        </span>
                        <span className="text-right text-caption font-semibold tabular-nums">
                            {c.n}
                        </span>
                    </div>
                ))}
            </div>
            <div className="text-caption leading-[1.45] text-muted">
                {laporan.catatan}
            </div>
            <div className="h-px bg-line-softer" />
            <div className="flex flex-col gap-2">
                <div className="text-body font-semibold">
                    Dampak ke pipeline
                </div>
                <div className="flex flex-wrap gap-x-8 gap-y-3">
                    {(
                        [
                            ['Naik tahap', laporan.dampak.naik],
                            ['Jadi HOT', laporan.dampak.hot],
                            ['Opt-out', laporan.dampak.stop],
                        ] as const
                    ).map(([label, n]) => (
                        <div key={label}>
                            <div className="text-caption text-muted">
                                {label}
                            </div>
                            <div className="text-judul font-semibold">{n}</div>
                        </div>
                    ))}
                </div>
            </div>
            <div className="flex flex-col gap-2">
                <div className="text-body font-semibold">
                    Balasan terbaru{' '}
                    <span className="font-normal text-muted">
                        · dijawab Adiva
                    </span>
                </div>
                {laporan.balasan.map((b) => (
                    <a
                        key={`${b.nama}-${b.jam}`}
                        href={percakapan.url()}
                        className="flex flex-col gap-[3px] border-b border-line-soft py-2 text-ink no-underline"
                    >
                        <span className="flex items-center gap-1.5">
                            <span className="grow text-body font-semibold">
                                {b.nama}
                            </span>
                            <span className="text-tag text-muted">{b.jam}</span>
                        </span>
                        <span className="text-caption text-ink-2">
                            {b.teks}
                        </span>
                    </a>
                ))}
            </div>
            <div className="flex flex-wrap gap-2 border-t border-line-softer pt-3.5">
                {laporan.draf && (
                    <>
                        <button
                            type="button"
                            className={tombol}
                            onClick={() =>
                                router.visit(
                                    baru.url({ query: { ubah: laporan.id } }),
                                )
                            }
                        >
                            Ubah draf
                        </button>
                        <button
                            type="button"
                            className={cn(tombol, 'text-red-text')}
                            onClick={() =>
                                window.confirm(
                                    `Hapus draf "${laporan.nama}"? Draf yang dihapus tidak bisa dikembalikan.`,
                                ) && router.delete(hapus.url(laporan.id))
                            }
                        >
                            Hapus draf
                        </button>
                    </>
                )}
                <button
                    type="button"
                    className={tombol}
                    onClick={() =>
                        // Buat kampanye fills itself from this one
                        router.visit(baru.url({ query: { dari: laporan.id } }))
                    }
                >
                    Duplikat
                </button>
                <button
                    type="button"
                    className={cn(tombol, laporan.draf && 'hidden')}
                    disabled={!laporan.bisaKirimUlang}
                    onClick={() =>
                        router.post(
                            kirimUlang.url(laporan.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    Kirim ulang ke yang gagal
                </button>
                <button
                    type="button"
                    className={tombol}
                    onClick={() =>
                        window.location.assign(ekspor.url(laporan.id))
                    }
                >
                    Ekspor penerima
                </button>
            </div>
        </aside>
    );
}
