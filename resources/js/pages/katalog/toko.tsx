import { Head, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { KatalogHeader } from '@/components/katalog/katalog-header';
import type { TabKatalog } from '@/components/katalog/katalog-header';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { KolomTabel } from '@/components/ui/data-table';
import { Select } from '@/components/ui/form';
import { Halaman } from '@/components/ui/halaman';
import type { InfoHalaman } from '@/components/ui/halaman';
import { Ikon } from '@/components/ui/ikon';
import { InputCari } from '@/components/ui/input-cari';
import { Panel } from '@/components/ui/panel';
import { StatStrip } from '@/components/ui/stat-strip';
import { Status } from '@/components/ui/status';
import { Mono, Note, T1, T2 } from '@/components/ui/teks';
import { useKonfirmasi } from '@/components/ui/konfirmasi';
import { putuskan, sambungkan } from '@/routes/accurate';
import { toko } from '@/routes/katalog';
import { sinkron } from '@/routes/katalog/toko';

type Barang = {
    id: number;
    nama: string;
    kode: string | null;
    barcode: string;
    kategori: string | null;
    satuan: string | null;
    harga: string | null;
};

type Filter = { cari: string; kategori: string };

type Props = {
    tab: TabKatalog;
    filter: Filter;
    barang: Barang[];
    halaman: InfoHalaman;
    kategori: string[];
    sinkron: {
        jumlah: number;
        tanpaHarga: number;
        /** The last run that went through, "28 Sep 13:00". */
        berhasil: string | null;
        gagal: { waktu: string; galat: string | null } | null;
        berjalan: boolean;
    };
    /** How the panel reaches Accurate; no token is ever sent here. */
    akses: {
        cara: 'oauth' | 'token' | null;
        oleh: string | null;
        sejak: string | null;
        /** The OAuth token's end, renewed by itself before then. */
        berlaku: string | null;
        /** ACCURATE_CLIENT_ID and ACCURATE_CLIENT_SECRET are set on the server. */
        bisaOauth: boolean;
    };
};

const kolom: KolomTabel<Barang>[] = [
    {
        id: 'nama',
        header: 'Barang',
        cell: ({ row }) => (
            <>
                <T1 className="font-semibold">{row.original.nama}</T1>
                <T2>{row.original.kode}</T2>
            </>
        ),
    },
    // next to the name, so phones see it without scrolling the table
    {
        id: 'harga',
        header: 'Harga jual',
        cell: ({ row }) =>
            row.original.harga ? (
                <>
                    <T1 className="tabular-nums">{row.original.harga}</T1>
                    <T2>per {row.original.satuan?.toLowerCase() ?? 'item'}</T2>
                </>
            ) : (
                <T2>Belum ada harga</T2>
            ),
    },
    {
        id: 'kategori',
        header: 'Kategori',
        cell: ({ row }) => <T1>{row.original.kategori}</T1>,
    },
    {
        id: 'barcode',
        header: 'Barcode',
        cell: ({ row }) => <Mono>{row.original.barcode}</Mono>,
    },
];

/** What changes when Accurate is read again. */
const DIMUAT_ULANG = ['sinkron', 'barang', 'halaman', 'kategori', 'tab'];

const query = (f: Filter, halaman?: number) => ({
    cari: f.cari || undefined,
    kategori: f.kategori || undefined,
    halaman: halaman && halaman > 1 ? halaman : undefined,
});

export default function KatalogBarangToko({
    tab,
    filter,
    barang,
    halaman,
    kategori,
    sinkron: info,
    akses,
}: Props) {
    const { props } = usePage();
    const bolehUbah = props.panel?.akses.katalog ?? false;
    const galatSambungan = props.errors?.accurate;
    const [isian, setIsian] = useState(filter);
    const pertama = useRef(true);
    const [diminta, setDiminta] = useState(false);
    const berjalan = info.berjalan || diminta;

    // search and the category reload the list, a moment after typing stops
    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(
            () =>
                router.get(
                    toko.url({ query: query(isian) }),
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

    // Accurate is read every minute: the list follows by itself
    usePoll(30000, {
        only: DIMUAT_ULANG,
        onSuccess: () => setDiminta(false),
    });

    // and every few seconds while a run started here is going
    useEffect(() => {
        if (!berjalan) {
            return;
        }

        const ulang = setInterval(
            () =>
                router.reload({
                    only: DIMUAT_ULANG,
                    onSuccess: () => setDiminta(false),
                }),
            4000,
        );

        return () => clearInterval(ulang);
    }, [berjalan]);

    const sinkronkan = () => {
        if (!bolehUbah || berjalan) {
            return;
        }

        router.post(
            sinkron.url(),
            {},
            { preserveScroll: true, onSuccess: () => setDiminta(true) },
        );
    };

    const adaFilter = isian.cari !== '' || isian.kategori !== '';

    return (
        <AppLayout active="katalog">
            <Head title="Barang toko" />
            <KatalogHeader
                aktif="toko"
                tab={tab}
                aksi={
                    <Button
                        varian="primary"
                        aria-disabled={!bolehUbah || berjalan}
                        title={bolehUbah ? undefined : 'Hanya supervisor'}
                        onClick={sinkronkan}
                    >
                        <Ikon nama="segarkan" ukuran={15} tebal={2} />
                        {berjalan ? 'Menyinkronkan…' : 'Sinkron sekarang'}
                    </Button>
                }
            />
            <IsiHalaman>
                <StatStrip
                    label="Barang toko"
                    stat={[
                        {
                            label: 'Barang di toko',
                            nilai: info.jumlah.toLocaleString('id-ID'),
                            meta: 'Punya barcode di Accurate',
                            sorot: true,
                        },
                        {
                            label: 'Kategori',
                            nilai: kategori.length,
                            meta: 'Dari Accurate',
                        },
                        {
                            label: 'Diperbarui',
                            nilai: info.berhasil ?? 'Belum pernah',
                            meta: 'Otomatis tiap menit',
                        },
                        {
                            label: 'Tanpa harga',
                            nilai: info.tanpaHarga,
                            meta: 'Adiva tidak menyebut harganya',
                        },
                    ]}
                />
                {galatSambungan && <Note nada="red">{galatSambungan}</Note>}
                {info.gagal && (
                    <Note nada="red">
                        Sinkron {info.gagal.waktu} gagal: {info.gagal.galat}.
                        {info.berhasil
                            ? ` Adiva masih memakai data ${info.berhasil}.`
                            : ' Adiva belum punya data barang toko.'}
                    </Note>
                )}
                <div className="grid grid-cols-[minmax(0,1fr)_320px] items-start gap-5 max-xl:grid-cols-1">
                    <Panel
                        judul={
                            adaFilter
                                ? `${halaman.total.toLocaleString('id-ID')} dari ${info.jumlah.toLocaleString('id-ID')} barang`
                                : `${info.jumlah.toLocaleString('id-ID')} barang`
                        }
                        sub="Diubah di Accurate, bukan di sini. Panel hanya membaca."
                        aksi={
                            <div className="flex flex-wrap items-center gap-2">
                                <Select
                                    aria-label="Kategori"
                                    className="h-8.5 w-auto rounded-[9px] px-2 text-caption"
                                    value={isian.kategori}
                                    onChange={(e) =>
                                        setIsian({
                                            ...isian,
                                            kategori: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">Semua kategori</option>
                                    {kategori.map((k) => (
                                        <option key={k} value={k}>
                                            {k}
                                        </option>
                                    ))}
                                </Select>
                                <InputCari
                                    lebar="w-[240px] max-md:w-full"
                                    aria-label="Cari barang"
                                    placeholder="Cari nama, kode, atau barcode"
                                    value={isian.cari}
                                    onChange={(e) =>
                                        setIsian({
                                            ...isian,
                                            cari: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        }
                        isiClassName="p-0"
                    >
                        {barang.length === 0 ? (
                            <div className="px-4.5 py-8 text-center text-body text-muted">
                                {adaFilter
                                    ? 'Tidak ada barang yang cocok.'
                                    : akses.cara === null
                                      ? 'Belum ada barang. Sambungkan Accurate dulu (kotak di samping).'
                                      : 'Belum ada barang. Tekan Sinkron sekarang untuk mengambil dari Accurate.'}
                            </div>
                        ) : (
                            <DataTable
                                kolom={kolom}
                                data={barang}
                                template="minmax(0, 1.6fr) 110px minmax(0, 0.8fr) 140px"
                                idBaris={(b) => String(b.id)}
                            />
                        )}
                        <Halaman
                            halaman={halaman}
                            onPindah={(ke) =>
                                router.get(
                                    toko.url({ query: query(isian, ke) }),
                                    {},
                                    { preserveState: true },
                                )
                            }
                        />
                    </Panel>
                    <div className="flex flex-col gap-5 max-xl:grid max-xl:grid-cols-2 max-xl:items-start max-md:grid-cols-1">
                        <SambunganAccurate akses={akses} />
                        <Panel
                            judul="Cara Adiva memakai data ini"
                            isiClassName="flex flex-col gap-2.5 px-[18px] pt-1 pb-4 text-body leading-[1.5] text-ink-2"
                        >
                            <p>
                                Ditanya barang di Toko Sawojajar, Adiva
                                mencarinya di sini dan menyebut harga jual apa
                                adanya.
                            </p>
                            <p>
                                Barang toko <b>hanya dijual langsung di toko</b>
                                : tidak bisa dipesan atau dikirim lewat
                                WhatsApp. Adiva mengajak customer datang ke
                                toko.
                            </p>
                            <p>
                                Stok tidak dibaca, jadi Adiva tidak menjanjikan
                                barangnya tersedia.
                            </p>
                            <p className="text-caption text-muted">
                                Barang dianggap dijual di toko kalau punya
                                barcode dan tidak nonaktif di Accurate.
                            </p>
                        </Panel>
                    </div>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

/**
 * How the panel reaches Accurate (OAuth: "Sambungkan Accurate", or an API Token in .env).
 * Connecting is a supervisor's job; staff only see the state.
 */
function SambunganAccurate({ akses }: { akses: Props['akses'] }) {
    const bolehSambung = usePage().props.panel?.akses.pengaturan ?? false;
    const [konfirmasi, tanya] = useKonfirmasi();
    const tombol =
        akses.cara === 'oauth' ? 'Sambungkan ulang' : 'Sambungkan Accurate';

    const putus = async () => {
        if (
            await tanya({
                judul: 'Putuskan Accurate?',
                pesan: 'Barang toko tidak diperbarui lagi sampai disambungkan ulang. Data yang sudah ada tetap disimpan.',
                ya: 'Putuskan',
                bahaya: true,
            })
        ) {
            router.post(putuskan.url(), {}, { preserveScroll: true });
        }
    };

    return (
        <Panel
            judul="Sambungan Accurate"
            sub="Database Bana Food · hanya melihat barang"
            isiClassName="flex flex-col gap-3 px-4.5 pt-1 pb-4 text-body leading-[1.5] text-ink-2"
        >
            {akses.cara === 'oauth' && (
                <p>
                    <Status nada="green">Tersambung</Status>{' '}
                    {akses.oleh && <>oleh {akses.oleh} </>}
                    {akses.sejak && <>sejak {akses.sejak}</>}.
                    {akses.berlaku && (
                        <span className="block text-caption text-muted">
                            Token berlaku sampai {akses.berlaku} dan diperbarui
                            sendiri sebelum habis.
                        </span>
                    )}
                </p>
            )}
            {akses.cara === 'token' && (
                <p>
                    <Status nada="gray">API Token</Status> diisi di server.
                    Berhasil atau tidaknya terlihat dari sinkron terakhir.
                </p>
            )}
            {akses.cara === null && (
                <p>
                    <Status nada="amber">Belum tersambung</Status> Login ke
                    Accurate sekali dan beri akses, lalu barang toko diambil
                    tiap menit.
                </p>
            )}
            {!akses.bisaOauth && akses.cara !== 'token' && (
                <p className="text-caption text-muted">
                    ACCURATE_CLIENT_ID dan ACCURATE_CLIENT_SECRET belum diisi di
                    server.
                </p>
            )}
            {bolehSambung && akses.bisaOauth && (
                <div className="flex flex-wrap gap-2">
                    <ButtonLink
                        href={sambungkan.url()}
                        varian={akses.cara === 'oauth' ? undefined : 'primary'}
                        ukuran="sm"
                    >
                        {tombol}
                    </ButtonLink>
                    {akses.cara === 'oauth' && (
                        <Button ukuran="sm" onClick={putus}>
                            Putuskan
                        </Button>
                    )}
                </div>
            )}
            {!bolehSambung && akses.cara === null && (
                <p className="text-caption text-muted">
                    Hanya supervisor yang bisa menyambungkan.
                </p>
            )}
            {konfirmasi}
        </Panel>
    );
}
