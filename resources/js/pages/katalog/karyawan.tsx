import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { KatalogHeader } from '@/components/katalog/katalog-header';
import type { TabKatalog } from '@/components/katalog/katalog-header';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Field, Input, Toggle } from '@/components/ui/form';
import type { KolomTabel } from '@/components/ui/data-table';
import { Ikon } from '@/components/ui/ikon';
import { Panel } from '@/components/ui/panel';
import { StatStrip } from '@/components/ui/stat-strip';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { KeyValue, Mono, Note, T1 } from '@/components/ui/teks';
import {
    harga as simpanHarga,
    rekap,
    simpan as simpanKaryawanRute,
    tambah as tambahKaryawan,
} from '@/routes/katalog/karyawan';

type Ringkasan = {
    bulan: string;
    order: number;
    berjalan: number;
    potongGaji: string;
    karyawan: number;
    nonaktif: number;
    rataRata: string | null;
};

type Pj = { nama: string; peran: string; nomor: string | null };

type OrderKaryawan = {
    id: number;
    kode: string;
    karyawan: string | null;
    item: string;
    nilai: string;
    status: { label: string; nada: Nada };
};

type Karyawan = {
    id: number;
    nama: string;
    nomor: string | null;
    bagian: string | null;
    order: number;
    potongGaji: string | null;
    aktif: boolean;
    isian: IsianKaryawan;
};

type IsianKaryawan = {
    nama: string;
    nomor_wa: string;
    bagian: string;
    aktif: boolean;
    catatan: string;
};

type HargaKaryawan = {
    id: number;
    varian: string;
    kode: string | null;
    harga: string | null;
    batas: string | null;
    hargaAngka: number | null;
};

type Props = {
    tab: TabKatalog;
    ringkasan: Ringkasan;
    pj: Pj;
    order: OrderKaryawan[];
    karyawan: Karyawan[];
    harga: HargaKaryawan[];
};

const kolomOrder: KolomTabel<OrderKaryawan>[] = [
    {
        id: 'order',
        header: 'Order',
        cell: ({ row }) => <Mono>{row.original.kode}</Mono>,
    },
    {
        id: 'karyawan',
        header: 'Karyawan',
        cell: ({ row }) => <T1>{row.original.karyawan}</T1>,
    },
    {
        id: 'item',
        header: 'Item',
        cell: ({ row }) => <T1>{row.original.item}</T1>,
    },
    {
        id: 'nilai',
        header: 'Nilai',
        meta: { kanan: true },
        cell: ({ row }) => <T1>{row.original.nilai}</T1>,
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <Status nada={row.original.status.nada}>
                {row.original.status.label}
            </Status>
        ),
    },
];

const kolomKaryawan: KolomTabel<Karyawan>[] = [
    {
        id: 'nama',
        header: 'Nama',
        cell: ({ row }) => (
            <T1 className="font-semibold">{row.original.nama}</T1>
        ),
    },
    {
        id: 'nomor',
        header: 'Nomor WhatsApp',
        cell: ({ row }) => (
            <Mono className="text-caption text-ink-2">
                {row.original.nomor}
            </Mono>
        ),
    },
    {
        id: 'bagian',
        header: 'Bagian',
        cell: ({ row }) => <T1>{row.original.bagian}</T1>,
    },
    {
        id: 'order',
        header: 'Order',
        meta: { kanan: true },
        cell: ({ row }) => <T1>{row.original.order}</T1>,
    },
    {
        id: 'potong',
        header: 'Potong gaji',
        meta: { kanan: true },
        cell: ({ row }) => <T1>{row.original.potongGaji ?? '—'}</T1>,
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) =>
            row.original.aktif ? (
                <Status nada="green">Aktif</Status>
            ) : (
                <Status nada="gray">Nonaktif</Status>
            ),
    },
];

const kolomHarga: KolomTabel<HargaKaryawan>[] = [
    {
        id: 'varian',
        header: 'Varian',
        cell: ({ row }) => <T1>{row.original.varian}</T1>,
    },
    {
        id: 'kode',
        header: 'Kode',
        cell: ({ row }) => (
            <Mono className="text-caption text-ink-2">{row.original.kode}</Mono>
        ),
    },
    {
        id: 'harga',
        header: 'Harga',
        cell: ({ row }) =>
            row.original.harga ? (
                <T1>{row.original.harga}</T1>
            ) : (
                <T1 className="font-normal text-muted">Belum diisi</T1>
            ),
    },
    {
        id: 'batas',
        header: 'Batas',
        cell: ({ row }) => <T1>{row.original.batas}</T1>,
    },
];

export default function KatalogKaryawan({
    tab,
    ringkasan,
    pj,
    order,
    karyawan,
    harga,
}: Props) {
    const bolehUbah = usePage().props.panel?.akses.katalog ?? false;
    // 'baru': "Tambah karyawan"; a row: editing that employee
    const [dibuka, setDibuka] = useState<Karyawan | 'baru' | null>(null);
    const [ubahHarga, setUbahHarga] = useState(false);

    return (
        <AppLayout active="katalog">
            <Head title="Program karyawan" />
            <KatalogHeader
                aktif="karyawan"
                tab={tab}
                aksi={
                    // a CSV of this month's employee orders, for payroll
                    <Button onClick={() => window.location.assign(rekap.url())}>
                        <Ikon nama="unduh" ukuran={15} tebal={2} />
                        Unduh rekap bulan ini
                    </Button>
                }
            />
            <IsiHalaman>
                <StatStrip
                    stat={[
                        {
                            label: `Order karyawan · ${ringkasan.bulan}`,
                            nilai: ringkasan.order,
                            meta: `${ringkasan.berjalan} masih berjalan`,
                        },
                        {
                            label: 'Total potong gaji',
                            nilai: ringkasan.potongGaji,
                            meta: `tiap order diteruskan ke ${pj.nama}`,
                        },
                        {
                            label: 'Karyawan terverifikasi',
                            nilai: ringkasan.karyawan,
                            meta: `${ringkasan.nonaktif} nonaktif`,
                        },
                        {
                            label: 'Rata-rata per order',
                            nilai: ringkasan.rataRata ?? '—',
                        },
                    ]}
                />
                <div className="grid grid-cols-[minmax(0,1fr)_340px] items-start gap-5 max-xl:grid-cols-1">
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel
                            judul="Order karyawan terbaru"
                            sub="Info pengambilan diumumkan di grup “Anak Bang Bana”"
                            aksi={
                                <ButtonLink href="/pesanan" ukuran="sm">
                                    Lihat di Pesanan
                                </ButtonLink>
                            }
                            isiClassName="p-0"
                        >
                            <DataTable
                                kolom={kolomOrder}
                                data={order}
                                template="140px 90px minmax(0, 1fr) 90px 140px"
                                idBaris={(o) => String(o.id)}
                            />
                        </Panel>
                        <Panel
                            judul="Karyawan terverifikasi"
                            sub={`${ringkasan.karyawan} orang · menampilkan ${karyawan.length}`}
                            aksi={
                                <Button
                                    ukuran="sm"
                                    aria-disabled={!bolehUbah}
                                    title={
                                        bolehUbah
                                            ? undefined
                                            : 'Hanya supervisor'
                                    }
                                    onClick={() =>
                                        bolehUbah && setDibuka('baru')
                                    }
                                >
                                    <Ikon nama="tambah" ukuran={15} tebal={2} />
                                    Tambah karyawan
                                </Button>
                            }
                            isiClassName="p-0"
                        >
                            {dibuka !== null && (
                                <PanelKaryawan
                                    key={dibuka === 'baru' ? 'baru' : dibuka.id}
                                    karyawan={dibuka === 'baru' ? null : dibuka}
                                    onTutup={() => setDibuka(null)}
                                />
                            )}
                            <DataTable
                                kolom={kolomKaryawan}
                                data={karyawan}
                                template="minmax(0, 0.8fr) 140px minmax(0, 1fr) 50px 100px 90px"
                                idBaris={(k) => String(k.id)}
                                terpilih={(k) =>
                                    dibuka !== null &&
                                    dibuka !== 'baru' &&
                                    k.id === dibuka.id
                                }
                                onPilih={(k) => bolehUbah && setDibuka(k)}
                            />
                        </Panel>
                        <Panel
                            judul="Harga karyawan"
                            sub="Dipakai Adiva hanya untuk nomor yang ada di daftar karyawan"
                            aksi={
                                !ubahHarga && (
                                    <Button
                                        ukuran="sm"
                                        aria-disabled={!bolehUbah}
                                        title={
                                            bolehUbah
                                                ? undefined
                                                : 'Hanya supervisor'
                                        }
                                        onClick={() =>
                                            bolehUbah && setUbahHarga(true)
                                        }
                                    >
                                        Ubah
                                    </Button>
                                )
                            }
                            isiClassName="p-0"
                        >
                            {ubahHarga ? (
                                <UbahHarga
                                    harga={harga}
                                    onTutup={() => setUbahHarga(false)}
                                />
                            ) : (
                                <DataTable
                                    kolom={kolomHarga}
                                    data={harga}
                                    template="minmax(0, 1fr) 90px 160px 160px"
                                    idBaris={(h) => String(h.id)}
                                />
                            )}
                        </Panel>
                    </div>
                    <div className="flex flex-col gap-5 max-xl:grid max-xl:grid-cols-2 max-xl:items-start max-md:grid-cols-1">
                        <Panel judul="Aturan program">
                            <div className="flex flex-col gap-3">
                                <KeyValue label="Penanggung jawab">
                                    {pj.nama} · {pj.peran}
                                </KeyValue>
                                <KeyValue label="Nomor WhatsApp PJ" mono>
                                    {pj.nomor ?? '—'}
                                </KeyValue>
                                <KeyValue label="Pembayaran">
                                    Potong gaji (metode POTONG GAJI)
                                </KeyValue>
                                <KeyValue label="Pengambilan">
                                    Sesuai info di grup “Anak Bang Bana”
                                </KeyValue>
                                <Note>
                                    Adiva memakai harga karyawan hanya jika
                                    nomor pengirim ada di tabel KARYAWAN. Order
                                    karyawan tidak lewat ACC: langsung dicatat
                                    dengan status Karyawan dan diteruskan ke{' '}
                                    {pj.nama}.
                                </Note>
                            </div>
                        </Panel>
                    </div>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

/** "Tambah karyawan" and editing one (clicking a row): above the list, in its panel. */
function PanelKaryawan({
    karyawan,
    onTutup,
}: {
    karyawan: Karyawan | null;
    onTutup: () => void;
}) {
    const form = useForm<IsianKaryawan>(
        karyawan?.isian ?? {
            nama: '',
            nomor_wa: '',
            bagian: '',
            aktif: true,
            catatan: '',
        },
    );
    const id = karyawan ? `karyawan-${karyawan.id}` : 'karyawan-baru';
    const galat = Object.values(form.errors)[0];

    const simpanKaryawan = () => {
        const pilihan = { preserveScroll: true, onSuccess: onTutup };

        if (karyawan) {
            form.put(simpanKaryawanRute.url(karyawan.id), pilihan);
        } else {
            form.post(tambahKaryawan.url(), pilihan);
        }
    };

    return (
        <div className="flex flex-col gap-3 border-b border-line bg-subtle px-[18px] py-4">
            <span className="text-body font-semibold">
                {karyawan ? `Ubah ${karyawan.nama}` : 'Karyawan baru'}
            </span>
            <div className="grid grid-cols-3 gap-3 max-md:grid-cols-1">
                <Field label="Nama" htmlFor={`${id}-nama`}>
                    <Input
                        id={`${id}-nama`}
                        value={form.data.nama}
                        onChange={(e) => form.setData('nama', e.target.value)}
                    />
                </Field>
                <Field label="Nomor WhatsApp" htmlFor={`${id}-nomor`}>
                    <Input
                        id={`${id}-nomor`}
                        mono
                        inputMode="tel"
                        value={form.data.nomor_wa}
                        onChange={(e) =>
                            form.setData('nomor_wa', e.target.value)
                        }
                    />
                </Field>
                <Field label="Bagian" htmlFor={`${id}-bagian`}>
                    <Input
                        id={`${id}-bagian`}
                        value={form.data.bagian}
                        onChange={(e) => form.setData('bagian', e.target.value)}
                    />
                </Field>
            </div>
            <Field label="Catatan" htmlFor={`${id}-catatan`}>
                <Input
                    id={`${id}-catatan`}
                    value={form.data.catatan}
                    onChange={(e) => form.setData('catatan', e.target.value)}
                />
            </Field>
            <div className="flex items-center gap-2 max-md:flex-wrap">
                <Toggle
                    aria-label="Karyawan aktif"
                    nyala={form.data.aktif}
                    onUbah={(nyala) => form.setData('aktif', nyala)}
                />
                <span className="grow text-body">
                    Aktif · boleh memakai harga karyawan
                </span>
                <Button onClick={onTutup}>Batal</Button>
                <Button
                    varian="primary"
                    aria-disabled={form.processing}
                    onClick={simpanKaryawan}
                >
                    Simpan
                </Button>
            </div>
            {galat && (
                <span role="alert" className="text-caption text-red-text">
                    {galat}
                </span>
            )}
        </div>
    );
}

/** "Harga karyawan", Ubah: the price and limit of every row, saved together. */
function UbahHarga({
    harga,
    onTutup,
}: {
    harga: HargaKaryawan[];
    onTutup: () => void;
}) {
    const form = useForm({
        harga: harga.map((h) => ({
            id: h.id,
            harga: h.hargaAngka === null ? '' : String(h.hargaAngka),
            batas: h.batas ?? '',
        })),
    });
    const galat = Object.values(form.errors)[0];

    const ubah = (i: number, isi: { harga?: string; batas?: string }) =>
        form.setData(
            'harga',
            form.data.harga.map((h, j) => (j === i ? { ...h, ...isi } : h)),
        );

    return (
        <div className="flex flex-col">
            {harga.map((h, i) => (
                <div
                    key={h.id}
                    className="grid grid-cols-[minmax(0,1fr)_160px_160px] items-center gap-3 border-b border-line-soft px-[18px] py-2 max-md:grid-cols-2 max-md:gap-y-1.5"
                >
                    <T1 className="max-md:col-span-2">{h.varian}</T1>
                    <Input
                        aria-label={`Harga karyawan ${h.varian}`}
                        placeholder="Harga (Rp)"
                        value={form.data.harga[i]?.harga ?? ''}
                        onChange={(e) => ubah(i, { harga: e.target.value })}
                        className="h-8"
                    />
                    <Input
                        aria-label={`Batas ${h.varian}`}
                        value={form.data.harga[i]?.batas ?? ''}
                        onChange={(e) => ubah(i, { batas: e.target.value })}
                        className="h-8"
                    />
                </div>
            ))}
            <div className="flex items-center justify-end gap-2 px-[18px] py-3">
                {galat && (
                    <span
                        role="alert"
                        className="grow text-caption text-red-text"
                    >
                        {galat}
                    </span>
                )}
                <Button onClick={onTutup}>Batal</Button>
                <Button
                    varian="primary"
                    aria-disabled={form.processing}
                    onClick={() =>
                        form.put(simpanHarga.url(), {
                            preserveScroll: true,
                            onSuccess: onTutup,
                        })
                    }
                >
                    Simpan
                </Button>
            </div>
        </div>
    );
}
