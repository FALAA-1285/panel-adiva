import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MenuAksi, UbahPenilaian } from '@/components/pelanggan/penilaian';
import type { IsianPenilaian } from '@/components/pelanggan/penilaian';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Select, Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { Avatar } from '@/components/ui/teks';
import { sedangMengetik } from '@/components/ui/topbar';
import { cocok, LAYAR } from '@/hooks/use-layar';
import { Halaman } from '@/components/ui/halaman';
import type { InfoHalaman } from '@/components/ui/halaman';
import { cn } from '@/lib/utils';
import { jeda, pelanggan, percakapan } from '@/routes';
import { ekspor, optout } from '@/routes/pelanggan';
import { detail as detailPesanan } from '@/routes/pesanan';

type Filter = { cari: string; tipe: string; tahap: string; urut: string };

type Baris = {
    id: number;
    inisial: string;
    nama: string;
    skor: string;
    keterangan: string;
};

type Profil = {
    id: number;
    inisial: string;
    nama: string;
    tipe: string;
    skor: string;
    nomor: string | null;
    kota: string | null;
    sejak: string | null;
    status: { label: string; nada: 'green' | 'amber' };
    langkah: {
        label: string;
        waktu: string;
        selesai: boolean;
        dilewati: boolean;
        kini: boolean;
    }[];
    penilaian: [string, string][];
    isian: IsianPenilaian;
    jedaId: number | null;
    nomorWa: string | null;
    ringkasan: string | null;
    catatanTetap: string | null;
    catatanSistem: string | null;
    pesanan: {
        jumlah: number;
        nilai: string;
        lunas: string;
        terakhir: {
            id: string;
            ringkas: string;
            total: string;
            status: { label: string; nada: Nada };
        } | null;
    };
    sapaan: {
        prospek: string;
        kesan: string;
        disapa: string;
        jedaJam: string | null;
        optout: boolean;
    };
    aktivitas: { waktu: string; teks: string; nada: string }[];
};

type Props = {
    filter: Filter;
    halaman: InfoHalaman;
    pilihan: {
        tipe: { nilai: string; label: string }[];
        tahap: { nilai: string; label: string }[];
    };
    jumlah: string;
    daftar: Baris[];
    profil: Profil | null;
};

const warnaSkor: Record<string, string> = {
    HOT: 'text-red-text',
    WARM: 'text-amber-text',
    COLD: 'text-muted',
};

/** The dots in Aktivitas: blue, grey, green. */
const warnaTitik: Record<string, string> = {
    blue: 'bg-primary',
    green: 'bg-green-text',
};

const query = (f: Filter, pilih?: number, halaman?: number) => ({
    cari: f.cari || undefined,
    tipe: f.tipe || undefined,
    tahap: f.tahap || undefined,
    urut: f.urut === 'nama' ? 'nama' : undefined,
    halaman: halaman && halaman > 1 ? halaman : undefined,
    pilih,
});

export default function Pelanggan({
    filter,
    halaman,
    pilihan,
    jumlah,
    daftar,
    profil,
}: Props) {
    const [isian, setIsian] = useState(filter);
    const [dipilih, setDipilih] = useState(profil?.id);
    const pertama = useRef(true);
    const { url, props } = usePage();
    const panel = props.panel;
    // below 1024px the list and the profile take turns (?pilih= opens the profile)
    const lihatProfil = new URLSearchParams(url.split('?')[1] ?? '').has(
        'pilih',
    );

    // search and filters reload the list, a moment after typing stops
    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(
            () =>
                router.get(
                    pelanggan.url({ query: query(isian) }),
                    {},
                    {
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            250,
        );

        return () => clearTimeout(tunda);
    }, [isian]);

    const pilih = (id: number) => {
        setDipilih(id);
        router.get(
            pelanggan.url({ query: query(isian, id) }),
            {},
            {
                only: ['profil'],
                preserveState: true,
                preserveScroll: true,
                // small screens: opening a profile is a step the browser's Back undoes
                replace: cocok(LAYAR.sedang),
            },
        );
    };

    const keDaftar = () =>
        router.get(
            pelanggan.url({ query: query(isian) }),
            {},
            { only: ['profil'], preserveState: true, preserveScroll: true },
        );

    // J / K: next or previous customer
    useEffect(() => {
        const tekan = (e: KeyboardEvent) => {
            const key = e.key.toLowerCase();

            if (
                sedangMengetik(e.target) ||
                e.metaKey ||
                e.ctrlKey ||
                e.altKey ||
                (key !== 'j' && key !== 'k')
            ) {
                return;
            }

            const sekarang = dipilih ?? profil?.id;
            const i = daftar.findIndex((b) => b.id === sekarang);
            const ke =
                daftar[
                    Math.min(
                        daftar.length - 1,
                        Math.max(0, i + (key === 'j' ? 1 : -1)),
                    )
                ];

            if (ke && ke.id !== sekarang) {
                e.preventDefault();
                pilih(ke.id);
            }
        };

        document.addEventListener('keydown', tekan);

        return () => document.removeEventListener('keydown', tekan);
    });

    return (
        <AppLayout active="customer" penuhLayar>
            <Head title="Pelanggan" />
            <PageHeader
                judul="Pelanggan"
                sub="Profil, pesanan, dan riwayat interaksi tiap customer dalam satu tempat"
                aksi={
                    // the full numbers in the file are for the staff who serve customers
                    panel?.akses.balas && (
                        <Button
                            className="px-3"
                            onClick={() =>
                                window.location.assign(
                                    ekspor.url({ query: query(isian) }),
                                )
                            }
                        >
                            <Ikon nama="unduh" ukuran={15} tebal={2} />
                            Ekspor CSV
                        </Button>
                    )
                }
            />
            <IsiHalaman className="flex-row gap-5 pb-6">
                <section
                    aria-label="Daftar pelanggan"
                    className={cn(
                        'box-content flex w-[300px] shrink-0 flex-col overflow-hidden rounded-card border border-line bg-surface',
                        'max-lg:box-border max-lg:w-full',
                        lihatProfil && 'max-lg:hidden',
                    )}
                >
                    <div className="flex flex-col gap-2.5 border-b border-line-softer px-4 py-3.5">
                        <label className="fokus-bingkai box-border flex h-10 items-center gap-2 rounded-control border border-line-control px-3 text-muted">
                            <Ikon nama="cari" tebal={2} />
                            <input
                                type="search"
                                aria-label="Cari pelanggan"
                                placeholder="Cari nama, nomor, kota"
                                className="grow border-none bg-transparent text-body text-ink outline-none"
                                value={isian.cari}
                                onChange={(e) =>
                                    setIsian({ ...isian, cari: e.target.value })
                                }
                            />
                        </label>
                        <div className="flex gap-1.5">
                            <Select
                                aria-label="Tipe"
                                className="h-9 w-auto grow rounded-[9px] px-2 text-caption"
                                value={isian.tipe}
                                onChange={(e) =>
                                    setIsian({ ...isian, tipe: e.target.value })
                                }
                            >
                                <option value="">Semua tipe</option>
                                {pilihan.tipe.map((p) => (
                                    <option key={p.nilai} value={p.nilai}>
                                        {p.label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                aria-label="Tahap"
                                className="h-9 w-auto grow rounded-[9px] px-2 text-caption"
                                value={isian.tahap}
                                onChange={(e) =>
                                    setIsian({
                                        ...isian,
                                        tahap: e.target.value,
                                    })
                                }
                            >
                                <option value="">Semua tahap</option>
                                {pilihan.tahap.map((p) => (
                                    <option key={p.nilai} value={p.nilai}>
                                        {p.label}
                                    </option>
                                ))}
                            </Select>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="grow text-caption text-muted">
                                {jumlah}
                            </span>
                            <Select
                                aria-label="Urutkan"
                                className="h-8 w-auto rounded-[9px] px-2 text-caption"
                                value={isian.urut}
                                onChange={(e) =>
                                    setIsian({ ...isian, urut: e.target.value })
                                }
                            >
                                <option value="terbaru">Chat terbaru</option>
                                <option value="nama">Nama A–Z</option>
                            </Select>
                        </div>
                    </div>
                    <div className="grow overflow-auto">
                        {daftar.map((b) => {
                            const aktif = b.id === (dipilih ?? profil?.id);

                            return (
                                <button
                                    key={b.id}
                                    type="button"
                                    aria-pressed={aktif}
                                    onClick={() => pilih(b.id)}
                                    className={cn(
                                        'flex w-full cursor-pointer items-center gap-2.5 border-0 border-b border-line-softer px-4 py-[11px] text-left text-ink',
                                        aktif
                                            ? 'bg-selected shadow-[inset_3px_0_0_var(--color-primary)]'
                                            : 'bg-surface hover:bg-subtle',
                                        'motion-safe:transition-colors motion-safe:duration-150',
                                    )}
                                >
                                    <Avatar
                                        inisial={b.inisial}
                                        ukuranTeks={12.5}
                                    />
                                    <span className="flex min-w-0 grow flex-col gap-[3px]">
                                        <span className="flex items-center gap-1.5">
                                            <span className="grow truncate text-body font-semibold">
                                                {b.nama}
                                            </span>
                                            <Skor skor={b.skor} />
                                        </span>
                                        <span className="text-caption text-muted">
                                            {b.keterangan}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                        {daftar.length === 0 && (
                            <div className="flex flex-col items-center justify-center p-8 text-center text-muted lg:hidden">
                                <span className="mb-2 flex size-10 items-center justify-center rounded-xl bg-subtle text-muted">
                                    <Ikon nama="cari" ukuran={18} />
                                </span>
                                <p className="text-body font-medium text-ink">
                                    Tidak ada pelanggan
                                </p>
                                <p className="mt-1 text-caption text-muted">
                                    {isian.cari || isian.tipe || isian.tahap
                                        ? 'Coba kata kunci lain.'
                                        : 'Customer yang chat dengan Adiva otomatis tercatat di sini.'}
                                </p>
                                {(isian.cari || isian.tipe || isian.tahap) && (
                                    <ButtonLink
                                        href={pelanggan.url()}
                                        ukuran="sm"
                                        className="mt-3"
                                    >
                                        Hapus pencarian
                                    </ButtonLink>
                                )}
                            </div>
                        )}
                    </div>
                    <Halaman
                        halaman={halaman}
                        onPindah={(ke) =>
                            router.get(
                                pelanggan.url({
                                    query: query(isian, undefined, ke),
                                }),
                                {},
                                { preserveState: true },
                            )
                        }
                    />
                </section>

                {profil ? (
                    <ProfilPelanggan
                        key={profil.id}
                        profil={profil}
                        pilihan={pilihan}
                        tampil={lihatProfil}
                        onKembali={keDaftar}
                    />
                ) : (
                    <TidakAdaPelangganDipilih
                        kosong={daftar.length === 0}
                        disaring={Boolean(
                            isian.cari || isian.tipe || isian.tahap,
                        )}
                    />
                )}
            </IsiHalaman>
        </AppLayout>
    );
}

/**
 * The right panel with no profile open. With an empty list it is the one empty
 * message on the page (Audit: two different ones used to show side by side).
 */
function TidakAdaPelangganDipilih({
    kosong,
    disaring,
}: {
    kosong: boolean;
    disaring: boolean;
}) {
    return (
        <section
            aria-label="Profil pelanggan"
            className="box-border flex min-w-0 grow flex-col items-center justify-center gap-3.5 rounded-card border border-line bg-surface p-12 text-center max-lg:hidden"
        >
            <span className="flex size-14 items-center justify-center rounded-2xl bg-primary-soft text-primary shadow-sm">
                <Ikon nama="pelanggan" ukuran={28} />
            </span>
            <div className="flex flex-col gap-1.5">
                <h3 className="text-judul font-semibold text-ink">
                    {!kosong
                        ? 'Pilih pelanggan'
                        : disaring
                          ? 'Tidak ada yang cocok'
                          : 'Belum ada pelanggan'}
                </h3>
                <p className="max-w-[420px] text-body text-muted leading-relaxed">
                    {!kosong
                        ? 'Pilih salah satu customer dari daftar di sebelah kiri untuk melihat profil, pesanan, dan riwayat interaksinya.'
                        : disaring
                          ? 'Tidak ada customer dengan kata kunci atau filter ini. Coba kata kunci lain.'
                          : 'Customer yang chat dengan Adiva otomatis tercatat di sini.'}
                </p>
            </div>
            {kosong && disaring && (
                <ButtonLink href={pelanggan.url()}>Hapus pencarian</ButtonLink>
            )}
        </section>
    );
}

function Skor({ skor }: { skor: string }) {
    return (
        <span
            className={cn(
                'text-tag font-semibold tracking-[0.02em] whitespace-nowrap',
                warnaSkor[skor] ?? 'text-muted',
            )}
        >
            {skor}
        </span>
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

function ProfilPelanggan({
    profil,
    pilihan,
    tampil,
    onKembali,
}: {
    profil: Profil;
    pilihan: Props['pilihan'];
    /** below 1024px: the profile is open (else the list shows) */
    tampil: boolean;
    onKembali: () => void;
}) {
    const [optoutAktif, setOptout] = useState(profil.sapaan.optout);
    const [ubahPenilaian, setUbahPenilaian] = useState(false);
    const bolehUbah = usePage().props.panel?.akses.balas ?? false;

    const ubahOptout = (nilai: boolean) => {
        setOptout(nilai);
        router.patch(
            optout.url(profil.id),
            { optout: nilai },
            { preserveScroll: true, preserveState: true, only: ['profil'] },
        );
    };

    const [bukaTeknis, setBukaTeknis] = useState(false);
    const KUNCI_BISNIS = new Set([
        'Tipe',
        'Tahap',
        'Skor prospek',
        'Minat produk',
        'Lokasi jualan',
        'Estimasi modal',
        'Kota',
        'Butuh admin',
    ]);
    const penilaianBisnis = profil.penilaian.filter(([k]) =>
        KUNCI_BISNIS.has(k),
    );
    const penilaianTeknis = profil.penilaian.filter(
        ([k]) => !KUNCI_BISNIS.has(k),
    );

    return (
        <section
            aria-label="Profil"
            className={cn(
                'box-border flex min-w-0 grow flex-col gap-4 overflow-auto',
                !tampil && 'max-lg:hidden',
            )}
        >
            <button
                type="button"
                onClick={onKembali}
                className="flex cursor-pointer items-center gap-1.5 self-start rounded-control border-none bg-transparent px-1 py-1 text-body font-semibold text-ink-2 hover:text-ink lg:hidden"
            >
                <Ikon nama="kembali" ukuran={16} />
                Semua pelanggan
            </button>
            <div className="flex flex-col gap-4 rounded-card border border-line bg-surface px-5 py-[18px] max-md:px-4">
                <div className="flex items-start gap-3.5 max-md:flex-wrap">
                    <Avatar
                        inisial={profil.inisial}
                        ukuran={48}
                        ukuranTeks={17}
                    />
                    <div className="flex min-w-0 grow flex-col gap-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="m-0 text-judul font-semibold tracking-[-0.01em]">
                                {profil.nama}
                            </h2>
                            <span className="text-body text-muted">
                                {profil.tipe}
                            </span>
                            <Skor skor={profil.skor} />
                        </div>
                        <div className="flex flex-wrap gap-3.5 text-caption text-ink-3">
                            {profil.nomor && (
                                <span className="font-mono">
                                    {profil.nomor}
                                </span>
                            )}
                            {profil.kota && <span>{profil.kota}</span>}
                            {profil.sejak && <span>{profil.sejak}</span>}
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1.5',
                                    profil.status.nada === 'green'
                                        ? 'text-green-text'
                                        : 'text-amber-text',
                                )}
                            >
                                <span
                                    className={cn(
                                        'size-1.5 rounded-full',
                                        profil.status.nada === 'green'
                                            ? 'bg-green-dot'
                                            : 'bg-amber-dot',
                                    )}
                                />
                                {profil.status.label}
                            </span>
                        </div>
                    </div>
                    <div className="flex gap-2 max-md:basis-full max-md:flex-wrap">
                        <ButtonLink
                            href={percakapan.url({
                                query: { pilih: profil.id },
                            })}
                            varian="primary"
                            className="box-content"
                        >
                            Buka chat
                        </ButtonLink>
                        <ButtonLink href={jeda.url()} className="box-content">
                            Jeda bot
                        </ButtonLink>
                        <MenuAksi
                            id={profil.id}
                            nama={profil.nama}
                            nomorWa={profil.nomorWa}
                            jedaId={profil.jedaId}
                            butuhAdmin={profil.isian.butuh_admin}
                        />
                    </div>
                </div>
                <div className="grid grid-cols-6 gap-1.5 max-md:grid-cols-3 max-md:gap-y-3">
                    {profil.langkah.map((l) => (
                        <div key={l.label} className="flex flex-col gap-[5px]">
                            <span
                                className={cn(
                                    'h-[3px] rounded-[2px]',
                                    l.selesai
                                        ? 'bg-primary'
                                        : l.dilewati
                                          ? 'bg-primary-line'
                                          : 'bg-line',
                                )}
                            />
                            <span
                                className={cn(
                                    'text-caption',
                                    l.kini ? 'font-semibold' : 'font-medium',
                                    l.selesai || l.kini
                                        ? 'text-ink'
                                        : 'text-muted',
                                )}
                            >
                                {l.label}
                            </span>
                            <span className="text-tag text-muted">
                                {l.waktu}
                            </span>
                        </div>
                    ))}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4 max-xl:grid-cols-1">
                <div className="flex flex-col gap-4">
                    <Kartu className="gap-3">
                        <div className="flex items-center">
                            <h3 className="m-0 grow text-panel font-semibold">
                                Penilaian Adiva
                            </h3>
                            {!ubahPenilaian && (
                                <Button
                                    ukuran="sm"
                                    className="px-3 text-caption"
                                    aria-disabled={!bolehUbah}
                                    title={
                                        bolehUbah
                                            ? undefined
                                            : 'Peran Anda tidak bisa mengubah'
                                    }
                                    onClick={() =>
                                        bolehUbah && setUbahPenilaian(true)
                                    }
                                >
                                    Ubah
                                </Button>
                            )}
                        </div>
                        {ubahPenilaian ? (
                            <UbahPenilaian
                                id={profil.id}
                                isian={profil.isian}
                                pilihan={pilihan}
                                onTutup={() => setUbahPenilaian(false)}
                            />
                        ) : (
                            <>
                                <div className="grid grid-cols-2 gap-x-3.5 gap-y-2.5">
                                    {(penilaianBisnis.length > 0
                                        ? penilaianBisnis
                                        : profil.penilaian
                                    ).map(([k, v]) => (
                                        <div
                                            key={k}
                                            className="flex min-w-0 flex-col gap-0.5"
                                        >
                                            <span className="text-tag text-muted">
                                                {k}
                                            </span>
                                            <span className="overflow-hidden text-body font-medium text-ellipsis">
                                                {v}
                                            </span>
                                        </div>
                                    ))}
                                </div>

                                {penilaianTeknis.length > 0 && (
                                    <div className="border-t border-line-soft pt-2.5">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setBukaTeknis(!bukaTeknis)
                                            }
                                            className="flex w-full cursor-pointer items-center justify-between text-caption font-medium text-ink-3 transition-colors hover:text-ink"
                                        >
                                            <span>
                                                Diagnostik &amp; sesi bot (
                                                {penilaianTeknis.length})
                                            </span>
                                            <Ikon
                                                nama="turun"
                                                ukuran={13}
                                                tebal={2.2}
                                                className={cn(
                                                    'transition-transform duration-150',
                                                    bukaTeknis && 'rotate-180',
                                                )}
                                            />
                                        </button>
                                        {bukaTeknis && (
                                            <div className="mt-2.5 grid grid-cols-2 gap-x-3.5 gap-y-2.5 rounded-control bg-subtle/60 p-2.5">
                                                {penilaianTeknis.map(
                                                    ([k, v]) => (
                                                        <div
                                                            key={k}
                                                            className="flex min-w-0 flex-col gap-0.5"
                                                        >
                                                            <span className="text-tag text-muted">
                                                                {k}
                                                            </span>
                                                            <span className="overflow-hidden font-mono text-caption text-ellipsis text-ink-2">
                                                                {v}
                                                            </span>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                        {profil.ringkasan && (
                            <div className="flex flex-col gap-1 border-t border-line-soft pt-3">
                                <span className="text-caption text-muted">
                                    Ringkasan terakhir
                                </span>
                                <span className="text-body leading-[1.5]">
                                    {profil.ringkasan}
                                </span>
                            </div>
                        )}
                    </Kartu>

                    <Kartu className="gap-2">
                        <div className="flex items-center">
                            <h3 className="m-0 grow text-panel font-semibold">
                                Catatan tetap
                            </h3>
                            <span className="text-caption text-muted">
                                Ditulis admin · dibaca Adiva
                            </span>
                        </div>
                        <div className="box-border min-h-[60px] rounded-card border border-line px-3 py-2 text-body leading-[1.45] whitespace-pre-line text-ink">
                            {profil.catatanTetap}
                        </div>
                        {profil.catatanSistem && (
                            <div className="flex flex-col gap-1 pt-1">
                                <span className="text-caption text-muted">
                                    Catatan sistem
                                </span>
                                <span className="text-caption text-ink-2">
                                    {profil.catatanSistem}
                                </span>
                            </div>
                        )}
                    </Kartu>
                </div>

                <div className="flex flex-col gap-4">
                    <Kartu className="gap-2.5">
                        <h3 className="m-0 text-panel font-semibold">
                            Pesanan
                        </h3>
                        <div className="flex flex-wrap gap-x-7 gap-y-3">
                            <Angka
                                label="Order"
                                nilai={String(profil.pesanan.jumlah)}
                            />
                            <Angka
                                label="Nilai order"
                                nilai={profil.pesanan.nilai}
                            />
                            <Angka
                                label="Sudah lunas"
                                nilai={profil.pesanan.lunas}
                            />
                        </div>
                        {profil.pesanan.terakhir && (
                            <Link
                                href={detailPesanan.url(
                                    profil.pesanan.terakhir.id,
                                )}
                                className="flex items-center gap-2.5 rounded-control border border-line px-3 py-2.5 text-ink no-underline"
                            >
                                <span className="flex grow flex-col gap-0.5">
                                    <span className="font-mono text-caption font-semibold">
                                        {profil.pesanan.terakhir.id}
                                    </span>
                                    <span className="text-caption text-ink-3">
                                        {profil.pesanan.terakhir.ringkas}
                                    </span>
                                </span>
                                <span className="flex flex-col items-end gap-0.5">
                                    <span className="text-body font-semibold">
                                        {profil.pesanan.terakhir.total}
                                    </span>
                                    <Status
                                        nada={
                                            profil.pesanan.terakhir.status.nada
                                        }
                                        className="text-caption"
                                    >
                                        {profil.pesanan.terakhir.status.label}
                                    </Status>
                                </span>
                            </Link>
                        )}
                    </Kartu>

                    <Kartu className="gap-2.5">
                        <h3 className="m-0 text-panel font-semibold">
                            Sapaan otomatis
                        </h3>
                        <div className="grid grid-cols-3 gap-2.5 max-md:grid-cols-1">
                            <Isian
                                k="Sapaan ulang prospek"
                                v={profil.sapaan.prospek}
                            />
                            <Isian
                                k="Tanya kesan pesanan"
                                v={profil.sapaan.kesan}
                            />
                            <Isian k="Sudah disapa" v={profil.sapaan.disapa} />
                        </div>
                        <div className="text-caption leading-[1.45] text-ink-3">
                            Prospek (Baru menyapa sampai Siap order) yang
                            berhenti membalas disapa sekali,{' '}
                            {profil.sapaan.jedaJam} jam setelah chat terakhir.
                            Setelah pesanan SELESAI, kesan ditanyakan sekali.
                            Tidak ada pengingat restock; tahap Tidak minat,
                            opt-out, dan customer yang dijeda tidak disapa.
                        </div>
                        <div className="flex items-center gap-2.5 border-t border-line-softer pt-2.5">
                            <span className="grow text-body font-semibold">
                                Status opt-out
                            </span>
                            <span className="text-caption text-ink-3">
                                {optoutAktif
                                    ? 'Tidak mau dihubungi'
                                    : 'Boleh dihubungi'}
                            </span>
                            <Toggle
                                aria-label="Tandai opt-out"
                                nyala={optoutAktif}
                                onUbah={ubahOptout}
                            />
                        </div>
                    </Kartu>

                    <Kartu className="gap-2">
                        <h3 className="m-0 text-panel font-semibold">
                            Aktivitas
                        </h3>
                        {profil.aktivitas.map((a, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-[78px_10px_minmax(0,1fr)] items-start gap-2 text-caption"
                            >
                                <span className="text-muted">{a.waktu}</span>
                                <span
                                    className={cn(
                                        'mt-[5px] size-2 rounded-full',
                                        warnaTitik[a.nada] ?? 'bg-faint',
                                    )}
                                />
                                <span className="leading-[1.4] text-ink-2">
                                    {a.teks}
                                </span>
                            </div>
                        ))}
                    </Kartu>
                </div>
            </div>
        </section>
    );
}

function Angka({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div>
            <div className="text-caption text-muted">{label}</div>
            <div className="text-judul font-semibold">{nilai}</div>
        </div>
    );
}

function Isian({ k, v }: { k: string; v: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="text-tag text-muted">{k}</span>
            <span className="text-body font-semibold">{v}</span>
        </div>
    );
}
