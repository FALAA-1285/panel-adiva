import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { KatalogHeader } from '@/components/katalog/katalog-header';
import { PanelProduk, useImporCsv } from '@/components/katalog/panel-produk';
import type { IsianProduk } from '@/components/katalog/panel-produk';
import type { TabKatalog } from '@/components/katalog/katalog-header';
import { UjiAdiva } from '@/components/katalog/uji-adiva';
import type { Uji } from '@/components/katalog/uji-adiva';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { KolomTabel } from '@/components/ui/data-table';
import { Ikon } from '@/components/ui/ikon';
import { InputCari } from '@/components/ui/input-cari';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import { Mono, T1, T2 } from '@/components/ui/teks';
import { cn } from '@/lib/utils';

type Produk = {
    id: number;
    nama: string;
    kategori: string | null;
    kemasan: string;
    rasa: string | null;
    tier: string | null;
    harga: string | null;
    target: string | null;
    adaFoto: boolean;
    isian: IsianProduk;
};

type Props = {
    tab: TabKatalog;
    produk: Produk[];
    uji: (Uji & { produkId: number | null }) | null;
};

const kolom: KolomTabel<Produk>[] = [
    {
        id: 'produk',
        header: 'Produk',
        cell: ({ row }) => (
            <>
                <T1 className="font-semibold">{row.original.nama}</T1>
                <T2>{row.original.kategori}</T2>
            </>
        ),
    },
    {
        id: 'kemasan',
        header: 'Kemasan',
        cell: ({ row }) => <T1>{row.original.kemasan}</T1>,
    },
    {
        id: 'rasa',
        header: 'Rasa',
        cell: ({ row }) => <T1>{row.original.rasa}</T1>,
    },
    {
        id: 'harga',
        header: 'Harga bertingkat',
        cell: ({ row }) => (
            <>
                <T1>{row.original.tier}</T1>
                <T2>{row.original.harga}</T2>
            </>
        ),
    },
    {
        id: 'target',
        header: 'Target pasar',
        cell: ({ row }) => <T1>{row.original.target}</T1>,
    },
    {
        id: 'foto',
        header: 'Foto',
        cell: ({ row }) =>
            row.original.adaFoto ? (
                <Status nada="green">Ada</Status>
            ) : (
                <Status nada="amber">Belum ada</Status>
            ),
    },
];

// Tools in the n8n workflow that read this data (Katalog.dc.html).
const tool = [
    ['Cari_Produk_per_Kategori', 'Produk'],
    ['Rekomendasi_Produk_Semua', 'Produk'],
    ['Paket_Reseller', 'Paket reseller'],
    ['Cari_Gambar_Produk', 'Gambar produk'],
    ['KB_Kebijakan', 'Kebijakan'],
    ['KB_Harga_Karyawan', 'Program karyawan'],
    ['Cek_Order_Saya', 'Pesanan'],
    ['Cek_Ongkir_Mengantar', 'API Mengantar'],
];

export default function KatalogProduk({ tab, produk, uji }: Props) {
    const bolehUbah = usePage().props.panel?.akses.katalog ?? false;
    // 'baru': "Tambah produk"; a row: editing that product
    const [dibuka, setDibuka] = useState<Produk | 'baru' | null>(null);
    const imporCsv = useImporCsv(() =>
        router.reload({ only: ['produk', 'tab'] }),
    );
    const [cari, setCari] = useState('');
    const tampil = useMemo(() => {
        const kata = cari.trim().toLowerCase();

        return kata
            ? produk.filter((p) =>
                  `${p.nama} ${p.kategori ?? ''}`.toLowerCase().includes(kata),
              )
            : produk;
    }, [cari, produk]);

    return (
        <AppLayout active="katalog">
            <Head title="Katalog" />
            <KatalogHeader
                aktif="produk"
                tab={tab}
                aksi={
                    <>
                        {imporCsv.input}
                        <Button
                            aria-disabled={!bolehUbah}
                            title={
                                bolehUbah
                                    ? 'Kolom: jenis_produk, kategori_produk, brand, kategori_kemasan, gramasi, rasa, target_pasar, deskripsi, satuan, harga (1-9:15000;10-49:14000;50+:13000)'
                                    : 'Hanya supervisor'
                            }
                            onClick={() => bolehUbah && imporCsv.buka()}
                        >
                            <Ikon nama="unggah" ukuran={15} tebal={2} />
                            Impor CSV
                        </Button>
                        <Button
                            varian="primary"
                            aria-disabled={!bolehUbah}
                            title={bolehUbah ? undefined : 'Hanya supervisor'}
                            onClick={() => bolehUbah && setDibuka('baru')}
                        >
                            <Ikon nama="tambah" ukuran={15} tebal={2} />
                            Tambah produk
                        </Button>
                    </>
                }
            />
            <IsiHalaman>
                {imporCsv.hasil && (
                    <span
                        role="status"
                        className={cn(
                            'text-body',
                            imporCsv.hasil.galat
                                ? 'text-red-text'
                                : 'text-ink-2',
                        )}
                    >
                        Impor CSV: {imporCsv.hasil.teks}
                    </span>
                )}
                {dibuka !== null && (
                    <PanelProduk
                        key={dibuka === 'baru' ? 'baru' : dibuka.id}
                        id={dibuka === 'baru' ? null : dibuka.id}
                        nama={dibuka === 'baru' ? undefined : dibuka.nama}
                        isian={dibuka === 'baru' ? null : dibuka.isian}
                        onTutup={() => setDibuka(null)}
                    />
                )}
                <div className="grid grid-cols-[minmax(0,1fr)_340px] items-start gap-5 max-xl:grid-cols-1">
                    <Panel
                        judul={`${produk.length} produk`}
                        sub="Dibaca Adiva lewat tool Cari_Produk_per_Kategori dan Rekomendasi_Produk_Semua"
                        aksi={
                            <InputCari
                                lebar="w-[240px]"
                                aria-label="Cari produk"
                                placeholder="Cari produk"
                                value={cari}
                                onChange={(e) => setCari(e.target.value)}
                            />
                        }
                        isiClassName="p-0"
                    >
                        <DataTable
                            kolom={kolom}
                            data={tampil}
                            template="minmax(0, 1.3fr) 90px minmax(0, 1fr) minmax(0, 1.1fr) minmax(0, 1.1fr) 90px"
                            idBaris={(p) => String(p.id)}
                            terpilih={(p) =>
                                dibuka !== null && dibuka !== 'baru'
                                    ? p.id === dibuka.id
                                    : p.id === uji?.produkId
                            }
                            onPilih={(p) => bolehUbah && setDibuka(p)}
                        />
                    </Panel>
                    <div className="flex flex-col gap-5 max-xl:grid max-xl:grid-cols-2 max-xl:items-start max-md:grid-cols-1">
                        <UjiAdiva uji={uji} bagian="KATALOG" />
                        <Panel
                            judul="Tool yang membaca katalog"
                            isiClassName="px-[18px] pt-1 pb-2"
                        >
                            {tool.map(([nama, bagian]) => (
                                <div
                                    key={nama}
                                    className="flex items-center gap-2 border-b border-line-soft py-[7px]"
                                >
                                    <Mono className="text-tag font-normal text-ink">
                                        {nama}
                                    </Mono>
                                    <span className="grow" />
                                    <span className="text-caption text-muted">
                                        {bagian}
                                    </span>
                                </div>
                            ))}
                        </Panel>
                    </div>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}
