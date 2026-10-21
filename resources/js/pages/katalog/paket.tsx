import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { KatalogHeader } from '@/components/katalog/katalog-header';
import type { TabKatalog } from '@/components/katalog/katalog-header';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import type { KolomTabel } from '@/components/ui/data-table';
import { Field, Input, Textarea, Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import { Mono, T1, T2 } from '@/components/ui/teks';
import { simpan, tambah } from '@/routes/katalog/paket';

type IsiPaket = { nama: string; jumlah: string };

type Paket = {
    id: number;
    kode: string;
    nama: string;
    isi: string | null;
    isiDetail: IsiPaket[];
    harga: number | null;
    hargaTeks: string | null;
    cocok: string | null;
    berat: string | null;
    aktif: boolean;
    tawarkan: string | null;
    terjual: number;
    diubah: string | null;
    diubahOleh: string | null;
};

type Props = {
    tab: TabKatalog;
    paket: Paket[];
    /** A package just added, opened in the editor. */
    pilih: number | null;
    namaProduk: string[];
};

const kolom: KolomTabel<Paket>[] = [
    {
        id: 'kode',
        header: 'Kode',
        cell: ({ row }) => (
            <Mono className="text-ink">{row.original.kode}</Mono>
        ),
    },
    {
        id: 'paket',
        header: 'Paket',
        cell: ({ row }) => (
            <>
                <T1 className="font-semibold">{row.original.nama}</T1>
                <T2>{row.original.isi}</T2>
            </>
        ),
    },
    {
        id: 'harga',
        header: 'Harga',
        cell: ({ row }) =>
            row.original.hargaTeks ? (
                <T1>{row.original.hargaTeks}</T1>
            ) : (
                <T1 className="font-normal text-muted">[harga paket]</T1>
            ),
    },
    {
        id: 'cocok',
        header: 'Cocok untuk',
        cell: ({ row }) => <T1>{row.original.cocok}</T1>,
    },
    {
        id: 'berat',
        header: 'Berat',
        cell: ({ row }) => <T1>{row.original.berat}</T1>,
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

export default function KatalogPaket({
    tab,
    paket,
    pilih: baru,
    namaProduk,
}: Props) {
    // 'baru': "Tambah paket" opened an empty editor
    const [pilih, setPilih] = useState<number | 'baru' | null>(
        baru ?? paket[0]?.id ?? null,
    );
    // after "Simpan" on a new one the server sends ?pilih=; the page is reused, so follow it here
    const [pilihServer, setPilihServer] = useState(baru);

    if (baru !== pilihServer) {
        setPilihServer(baru);

        if (baru !== null) {
            setPilih(baru);
        }
    }
    const terpilih = paket.find((p) => p.id === pilih);
    const bolehUbah = usePage().props.panel?.akses.katalog ?? false;

    return (
        <AppLayout active="katalog">
            <Head title="Paket reseller" />
            <KatalogHeader
                aktif="paket"
                tab={tab}
                aksi={
                    <Button
                        varian="primary"
                        aria-disabled={!bolehUbah}
                        title={bolehUbah ? undefined : 'Hanya supervisor'}
                        onClick={() => bolehUbah && setPilih('baru')}
                    >
                        <Ikon nama="tambah" ukuran={15} tebal={2} />
                        Tambah paket
                    </Button>
                }
            />
            <IsiHalaman>
                <div className="grid grid-cols-[minmax(0,1fr)_420px] items-start gap-5 max-xl:grid-cols-1">
                    <Panel
                        judul={`${paket.length} paket`}
                        sub="Ditawarkan Adiva ke calon reseller yang belum tahu mau mulai dari mana"
                        isiClassName="p-0"
                    >
                        <DataTable
                            kolom={kolom}
                            data={paket}
                            template="70px minmax(0, 1.4fr) 110px minmax(0, 1.1fr) minmax(0, 0.9fr) 80px"
                            idBaris={(p) => String(p.id)}
                            terpilih={(p) => p.id === pilih}
                            onPilih={(p) => setPilih(p.id)}
                        />
                    </Panel>
                    {pilih === 'baru' ? (
                        <PenyuntingPaket
                            key="baru"
                            paket={null}
                            namaProduk={namaProduk}
                            onBatal={() => setPilih(paket[0]?.id ?? null)}
                        />
                    ) : (
                        terpilih && (
                            <PenyuntingPaket
                                key={terpilih.id}
                                paket={terpilih}
                                namaProduk={namaProduk}
                            />
                        )
                    )}
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

/** The right-hand editor for the selected package, or a new one ("Tambah paket"). */
function PenyuntingPaket({
    paket,
    namaProduk,
    onBatal,
}: {
    paket: Paket | null;
    namaProduk: string[];
    onBatal?: () => void;
}) {
    const form = useForm({
        kode_paket: paket?.kode ?? '',
        nama_paket: paket?.nama ?? '',
        isi_detail: paket?.isiDetail ?? [],
        harga: paket?.harga == null ? '' : String(paket.harga),
        berat: paket?.berat ?? '',
        tawarkan_ketika: paket?.tawarkan ?? '',
        aktif: paket?.aktif ?? true,
    });
    // rows already in the package show their name; added rows get a field for it
    const [asli, setAsli] = useState(paket?.isiDetail.length ?? 0);
    const galat = Object.values(form.errors)[0];

    const ubahIsi = (i: number, isi: Partial<IsiPaket>) =>
        form.setData(
            'isi_detail',
            form.data.isi_detail.map((x, j) =>
                j === i ? { ...x, ...isi } : x,
            ),
        );

    const simpanPaket = () =>
        paket
            ? form.put(simpan.url(paket.id), { preserveScroll: true })
            : form.post(tambah.url(), { preserveScroll: true });

    const diubah = paket
        ? [
              paket.diubah && `terakhir diubah ${paket.diubah}`,
              paket.diubahOleh && `oleh ${paket.diubahOleh}`,
          ]
              .filter(Boolean)
              .join(' ')
        : '';

    return (
        <Panel
            judul={paket ? `${paket.kode} · ${paket.nama}` : 'Paket baru'}
            sub={
                paket
                    ? `Terjual ${paket.terjual}× bulan ini${diubah ? ` · ${diubah}` : ''}`
                    : 'Isi kode, nama, dan isi paket, lalu simpan.'
            }
            aksi={
                <div className="flex gap-2">
                    {onBatal && (
                        <Button ukuran="sm" onClick={onBatal}>
                            Batal
                        </Button>
                    )}
                    <Button
                        varian="primary"
                        ukuran="sm"
                        onClick={simpanPaket}
                        aria-disabled={form.processing}
                    >
                        Simpan
                    </Button>
                </div>
            }
        >
            <div className="flex flex-col gap-3.5">
                <div className="grid grid-cols-[100px_minmax(0,1fr)] gap-3">
                    <Field label="Kode" htmlFor="kode_paket">
                        <Input
                            id="kode_paket"
                            mono
                            value={form.data.kode_paket}
                            onChange={(e) =>
                                form.setData('kode_paket', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Nama paket" htmlFor="nama_paket">
                        <Input
                            id="nama_paket"
                            value={form.data.nama_paket}
                            onChange={(e) =>
                                form.setData('nama_paket', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <div className="flex flex-col gap-1">
                    <span className="text-body font-semibold text-ink">
                        Isi paket
                    </span>
                    {form.data.isi_detail.map((isi, i) => (
                        <div
                            // names can repeat or be empty while typing
                            key={i}
                            className="grid grid-cols-[minmax(0,1fr)_90px_28px] items-center gap-2 border-b border-line-soft py-1.5"
                        >
                            {i < asli ? (
                                <span className="text-body">{isi.nama}</span>
                            ) : (
                                <Input
                                    aria-label="Nama produk"
                                    list="nama-produk"
                                    placeholder="Pilih atau ketik produk"
                                    value={isi.nama}
                                    onChange={(e) =>
                                        ubahIsi(i, { nama: e.target.value })
                                    }
                                    className="h-8"
                                />
                            )}
                            <Input
                                aria-label={`Jumlah ${isi.nama}`}
                                value={isi.jumlah}
                                onChange={(e) =>
                                    ubahIsi(i, { jumlah: e.target.value })
                                }
                                className="h-8 text-right"
                            />
                            <button
                                type="button"
                                aria-label="Hapus"
                                onClick={() => {
                                    if (i < asli) {
                                        setAsli(asli - 1);
                                    }

                                    form.setData(
                                        'isi_detail',
                                        form.data.isi_detail.filter(
                                            (_, j) => j !== i,
                                        ),
                                    );
                                }}
                                className="flex size-7 cursor-pointer items-center justify-center border-none bg-transparent text-muted"
                            >
                                <Ikon nama="tutup" ukuran={15} />
                            </button>
                        </div>
                    ))}
                    <datalist id="nama-produk">
                        {namaProduk.map((n) => (
                            <option key={n} value={n} />
                        ))}
                    </datalist>
                    <div className="pt-1.5">
                        <Button
                            varian="link"
                            onClick={() =>
                                form.setData('isi_detail', [
                                    ...form.data.isi_detail,
                                    { nama: '', jumlah: '' },
                                ])
                            }
                        >
                            <Ikon nama="tambah" ukuran={15} tebal={2} />
                            Tambah produk
                        </Button>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Harga paket" htmlFor="harga">
                        <Input
                            id="harga"
                            placeholder="[harga paket]"
                            value={form.data.harga}
                            onChange={(e) =>
                                form.setData('harga', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Berat kirim" htmlFor="berat">
                        <Input
                            id="berat"
                            value={form.data.berat}
                            onChange={(e) =>
                                form.setData('berat', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <Field
                    label="Tawarkan ketika"
                    htmlFor="tawarkan_ketika"
                    hint="Ditulis seperti instruksi untuk Adiva. Dipakai di tahap Tanya produk dan Tanya harga."
                >
                    <Textarea
                        id="tawarkan_ketika"
                        rows={3}
                        value={form.data.tawarkan_ketika}
                        onChange={(e) =>
                            form.setData('tawarkan_ketika', e.target.value)
                        }
                    />
                </Field>
                <div className="flex items-center gap-2.5 border-t border-line-soft pt-1">
                    <span className="grow pt-2.5 text-body font-medium">
                        Boleh ditawarkan Adiva
                    </span>
                    <span className="pt-2.5">
                        <Toggle
                            aria-label="Boleh ditawarkan Adiva"
                            nyala={form.data.aktif}
                            onUbah={(nyala) => form.setData('aktif', nyala)}
                        />
                    </span>
                </div>
                {galat && (
                    <span role="alert" className="text-caption text-red-text">
                        {galat}
                    </span>
                )}
            </div>
        </Panel>
    );
}
