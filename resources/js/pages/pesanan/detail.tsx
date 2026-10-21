import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import {
    Button,
    ButtonLink,
    IconButton,
    kelasIconButton,
} from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/form';
import type { KolomTabel } from '@/components/ui/data-table';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Status, titikNada } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { KeyValue, Note, T1, T2 } from '@/components/ui/teks';
import { cn } from '@/lib/utils';
import { percakapan, pesanan } from '@/routes';
import { aksi as perintah, item as ruteItem } from '@/routes/pesanan';

/** A row of the "Ubah item" form, as typed. */
type BarisItem = { nama: string; qty: string; harga: string };

type Item = {
    nama: string;
    keterangan: string | null;
    harga: string;
    qty: number;
    subtotal: string;
};

type Invoice = {
    nomor: string;
    draf: boolean;
    /** The PDF n8n made at ACC (null for a draft or before n8n saved it). */
    pdf: string | null;
    toko: string;
    perusahaan: string;
    tanggal: string;
    kepada: string;
    alamat: string | null;
    order: string;
    item: { nama: string; qty: number; harga: string; subtotal: string }[];
    produk: string;
    ongkir: { label: string; nilai: string } | null;
    total: string;
    rekening: string | null;
    ditahan: string | null;
    catatan: string | null;
};

type Props = {
    order: { id: string; status: { label: string; nada: Nada }; sub: string };
    langkah: {
        label: string;
        selesai: boolean;
        kini: boolean;
        mati: boolean;
        waktu: string;
    }[];
    item: Item[];
    ubahItem: { alasan: string | null; isian: BarisItem[] };
    total: {
        produk: string;
        ongkir: { label: string; nilai: string } | null;
        tagihan: string;
    };
    pengiriman: {
        penerima: string;
        nomor: string;
        alamat: string;
        kurir: string;
        berat: string;
        metode: string;
        batasBayar: string;
        nomorBot: string;
        dikirimOleh: string;
    };
    riwayat: { waktu: string; teks: string; nada: Nada }[];
    invoice: Invoice;
    /** "TOLAK" and "ACC" while they apply. */
    aksi: string[];
};

const kolomItem: KolomTabel<Item>[] = [
    {
        id: 'produk',
        header: 'Produk',
        cell: ({ row }) => (
            <>
                <T1>{row.original.nama}</T1>
                {row.original.keterangan && <T2>{row.original.keterangan}</T2>}
            </>
        ),
    },
    {
        id: 'harga',
        header: 'Harga',
        meta: { kanan: true },
        cell: ({ row }) => <T1>{row.original.harga}</T1>,
    },
    {
        id: 'jumlah',
        header: 'Jumlah',
        meta: { kanan: true },
        cell: ({ row }) => <T1>×{row.original.qty}</T1>,
    },
    {
        id: 'subtotal',
        header: 'Subtotal',
        meta: { kanan: true },
        cell: ({ row }) => (
            <T1 className="font-semibold">{row.original.subtotal}</T1>
        ),
    },
];

export default function DetailPesanan({
    order,
    langkah,
    item,
    ubahItem,
    total,
    pengiriman,
    riwayat,
    invoice,
    aksi,
}: Props) {
    const [mengubahItem, setMengubahItem] = useState(false);
    const jalankan = (kode: string) =>
        router.post(
            perintah.url(order.id),
            { aksi: kode },
            { preserveScroll: true },
        );

    const [modeTampilan, setModeTampilan] = useState<
        'rincian' | 'invoice' | 'berdampingan'
    >('rincian');

    const rincianElement = (
        <>
            <Panel>
                <div
                    className={cn(
                        'grid gap-2',
                        langkah.length === 3 ? 'grid-cols-3' : 'grid-cols-5',
                    )}
                >
                    {langkah.map((l) => (
                        <div key={l.label} className="flex flex-col gap-1.5">
                            <span
                                className={cn(
                                    'h-[3px] rounded-[2px]',
                                    l.mati
                                        ? 'bg-note-red-line'
                                        : l.selesai
                                          ? 'bg-primary'
                                          : 'bg-line',
                                )}
                            />
                            <span
                                className={cn(
                                    'text-caption',
                                    l.kini ? 'font-semibold' : 'font-medium',
                                    l.selesai ? 'text-ink' : 'text-muted',
                                )}
                            >
                                {l.label}
                            </span>
                            <span className="text-caption text-muted">
                                {l.waktu}
                            </span>
                        </div>
                    ))}
                </div>
            </Panel>

            <Panel
                judul="Item"
                sub="Harga diambil dari tabel harga bertingkat saat order dicatat"
                aksi={
                    !mengubahItem && (
                        <Button
                            ukuran="sm"
                            aria-disabled={ubahItem.alasan !== null}
                            title={ubahItem.alasan ?? undefined}
                            onClick={() =>
                                ubahItem.alasan === null &&
                                setMengubahItem(true)
                            }
                        >
                            <Ikon nama="ubah" ukuran={15} tebal={2} />
                            Ubah item
                        </Button>
                    )
                }
                isiClassName="p-0"
            >
                {mengubahItem ? (
                    <UbahItem
                        orderId={order.id}
                        isian={ubahItem.isian}
                        onTutup={() => setMengubahItem(false)}
                    />
                ) : (
                    <DataTable
                        kolom={kolomItem}
                        data={item}
                        template="minmax(0, 1fr) 100px 70px 110px"
                    />
                )}
                <div className="flex flex-col gap-1.5 px-[18px] pt-3 pb-4">
                    <div className="flex text-body text-ink-3">
                        <span className="grow">Total produk</span>
                        <span>{total.produk}</span>
                    </div>
                    {total.ongkir && (
                        <div className="flex text-body text-ink-3">
                            <span className="grow">{total.ongkir.label}</span>
                            <span>{total.ongkir.nilai}</span>
                        </div>
                    )}
                    <div className="flex border-t border-line-soft pt-1.5 text-panel font-semibold">
                        <span className="grow">Total tagihan</span>
                        <span>{total.tagihan}</span>
                    </div>
                </div>
            </Panel>

            <Panel judul="Pengiriman & pembayaran">
                <div className="grid grid-cols-2 gap-x-5 gap-y-3.5">
                    <KeyValue label="Penerima">{pengiriman.penerima}</KeyValue>
                    <KeyValue label="Nomor WhatsApp" mono>
                        {pengiriman.nomor}
                    </KeyValue>
                    <KeyValue label="Alamat" className="col-span-2">
                        {pengiriman.alamat}
                    </KeyValue>
                    <KeyValue label="Kurir">{pengiriman.kurir}</KeyValue>
                    <KeyValue label="Berat">{pengiriman.berat}</KeyValue>
                    <KeyValue label="Metode bayar">
                        {pengiriman.metode}
                    </KeyValue>
                    <KeyValue label="Batas bayar">
                        {pengiriman.batasBayar}
                    </KeyValue>
                    <KeyValue label="Lewat nomor bot">
                        {pengiriman.nomorBot}
                    </KeyValue>
                    <KeyValue label="Dikirim ke customer oleh">
                        {pengiriman.dikirimOleh}
                    </KeyValue>
                </div>
            </Panel>

            <Panel judul="Riwayat" isiClassName="px-[18px] pt-1.5 pb-2.5">
                {riwayat.map((r, i) => (
                    <div
                        key={i}
                        className="grid grid-cols-[48px_12px_minmax(0,1fr)] items-start gap-2 py-2"
                    >
                        <span className="text-caption text-muted">
                            {r.waktu}
                        </span>
                        <span
                            className={cn(
                                'mt-1.5 size-[7px] rounded-full',
                                titikNada(r.nada),
                            )}
                        />
                        <span className="text-body leading-[1.5] text-ink-2">
                            {r.teks}
                        </span>
                    </div>
                ))}
            </Panel>
        </>
    );

    const invoiceElement = (
        <Panel
            className="self-start"
            judul="Pratinjau invoice"
            sub="PDF yang dikirim ke customer setelah ACC"
            aksi={
                <>
                    <IconButton
                        aria-label="Cetak"
                        onClick={() => window.print()}
                    >
                        <Ikon nama="cetak" />
                    </IconButton>
                    {invoice.pdf ? (
                        <a
                            href={invoice.pdf}
                            target="_blank"
                            rel="noreferrer"
                            download
                            aria-label="Unduh PDF"
                            className={kelasIconButton}
                        >
                            <Ikon nama="unduh" />
                        </a>
                    ) : (
                        <IconButton
                            aria-label="Unduh PDF"
                            aria-disabled
                            title="PDF dibuat n8n setelah ACC"
                        >
                            <Ikon nama="unduh" />
                        </IconButton>
                    )}
                </>
            }
        >
            <div className="flex flex-col gap-3.5">
                <div className="flex justify-center rounded-control bg-line-softer py-5 max-md:justify-start max-md:overflow-x-auto max-md:px-3">
                    <KertasInvoice invoice={invoice} />
                </div>
                <Note>
                    Nomor invoice final dibuat saat ACC. Jika PDF gagal dibuat
                    dua kali, Adiva otomatis mengirim versi teks dan admin
                    dikabari.
                </Note>
            </div>
        </Panel>
    );

    return (
        <AppLayout active="orders">
            <Head title="Detail pesanan" />
            <PageHeader
                breadcrumb={[
                    { label: 'Pesanan', href: pesanan.url() },
                    { label: order.id },
                ]}
                judul={
                    <>
                        {order.id}{' '}
                        <span className="ml-2.5 align-[3px]">
                            <Status
                                nada={order.status.nada}
                                className="text-body"
                            >
                                {order.status.label}
                            </Status>
                        </span>
                    </>
                }
                sub={order.sub}
                tab={[
                    {
                        label: 'Rincian pesanan',
                        aktif: modeTampilan === 'rincian',
                        onClick: () => setModeTampilan('rincian'),
                    },
                    {
                        label: 'Pratinjau invoice',
                        aktif: modeTampilan === 'invoice',
                        onClick: () => setModeTampilan('invoice'),
                    },
                    {
                        label: 'Berdampingan',
                        aktif: modeTampilan === 'berdampingan',
                        onClick: () => setModeTampilan('berdampingan'),
                    },
                ]}
                aksi={
                    <>
                        <ButtonLink href={percakapan.url()} varian="ghost">
                            <Ikon nama="whatsapp" ukuran={15} tebal={2} />
                            Buka chat
                        </ButtonLink>
                        {aksi.includes('TOLAK') && (
                            <Button
                                varian="danger"
                                onClick={() => jalankan('TOLAK')}
                            >
                                Tolak
                            </Button>
                        )}
                        {aksi.includes('ACC') && (
                            <Button
                                varian="primary"
                                onClick={() => jalankan('ACC')}
                            >
                                ACC &amp; kirim invoice
                            </Button>
                        )}
                    </>
                }
            />
            <IsiHalaman>
                {modeTampilan === 'rincian' && (
                    <div className="flex flex-col gap-5">
                        {rincianElement}
                        <div className="hidden print:block">
                            <KertasInvoice invoice={invoice} />
                        </div>
                    </div>
                )}

                {modeTampilan === 'invoice' && (
                    <div className="mx-auto flex w-full max-w-[620px] flex-col gap-5">
                        {invoiceElement}
                    </div>
                )}

                {modeTampilan === 'berdampingan' && (
                    <div className="grid grid-cols-[minmax(0,1fr)_560px] items-start gap-5 max-xl:grid-cols-1">
                        <div className="flex min-w-0 flex-col gap-5">
                            {rincianElement}
                        </div>
                        {invoiceElement}
                    </div>
                )}
            </IsiHalaman>
        </AppLayout>
    );
}

const KOLOM_INVOICE = 'grid-cols-[minmax(0,1fr)_36px_70px_78px]';

/** The invoice page as the customer receives it, at the preview's scale. */
function KertasInvoice({ invoice }: { invoice: Invoice }) {
    return (
        <div
            data-cetak
            className="box-border flex h-[679px] w-[480px] flex-col gap-[18px] self-center border border-line bg-surface px-9 pt-9 pb-[30px] text-ink"
        >
            <div className="flex items-start gap-2.5">
                <span className="flex size-7 items-center justify-center rounded-chip bg-primary-ink text-key font-bold text-white">
                    BB
                </span>
                <div className="flex grow flex-col gap-px">
                    <span className="text-body font-semibold">
                        {invoice.toko}
                    </span>
                    <span className="text-nota-mini text-muted">
                        {invoice.perusahaan}
                    </span>
                </div>
                <div className="flex flex-col items-end gap-0.5">
                    <span className="text-panel font-semibold tracking-[0.04em]">
                        INVOICE
                    </span>
                    <span className="font-mono text-nota-mini text-muted">
                        {invoice.nomor}
                        {invoice.draf && ' · draf'}
                    </span>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4 text-nota leading-[1.5]">
                <div>
                    <div className="text-muted">Kepada</div>
                    <div className="font-semibold">{invoice.kepada}</div>
                    {invoice.alamat && <div>{invoice.alamat}</div>}
                </div>
                <div className="text-right">
                    <div className="text-muted">Tanggal</div>
                    <div>{invoice.tanggal}</div>
                    <div className="mt-1 text-muted">Order</div>
                    <div className="font-mono">{invoice.order}</div>
                </div>
            </div>

            <div>
                <div
                    className={cn(
                        'grid gap-2 border-b border-ink py-1.5 text-nota-mini font-semibold',
                        KOLOM_INVOICE,
                    )}
                >
                    <span>Produk</span>
                    <span className="text-right">Qty</span>
                    <span className="text-right">Harga</span>
                    <span className="text-right">Subtotal</span>
                </div>
                {invoice.item.map((it, i) => (
                    <div
                        key={i}
                        className={cn(
                            'grid gap-2 border-b border-line-quiet py-[7px] text-key',
                            KOLOM_INVOICE,
                        )}
                    >
                        <span>{it.nama}</span>
                        <span className="text-right">{it.qty}</span>
                        <span className="text-right">{it.harga}</span>
                        <span className="text-right">{it.subtotal}</span>
                    </div>
                ))}
                <div className="flex flex-col items-end gap-1 pt-2 text-nota">
                    <div className="flex gap-6">
                        <span className="text-muted">Total produk</span>
                        <span className="w-[78px] text-right">
                            {invoice.produk}
                        </span>
                    </div>
                    {invoice.ongkir && (
                        <div className="flex gap-6">
                            <span className="text-muted">
                                {invoice.ongkir.label}
                            </span>
                            <span className="w-[78px] text-right">
                                {invoice.ongkir.nilai}
                            </span>
                        </div>
                    )}
                    <div className="flex gap-6 pt-1 text-caption font-semibold">
                        <span>Total</span>
                        <span className="w-[90px] text-right">
                            {invoice.total}
                        </span>
                    </div>
                </div>
            </div>

            <div className="mt-auto flex flex-col gap-1.5 bg-subtle p-3 text-nota leading-[1.5]">
                {invoice.rekening && (
                    <div>
                        <span className="text-muted">Transfer ke</span> ·{' '}
                        {invoice.rekening}
                    </div>
                )}
                {invoice.ditahan && (
                    <div>
                        <span className="text-muted">Pesanan ditahan</span> ·{' '}
                        {invoice.ditahan}
                    </div>
                )}
                {invoice.catatan && <div>{invoice.catatan}</div>}
                {invoice.rekening && (
                    <div>
                        Kirim foto bukti transfer ke WhatsApp ini, Adiva akan
                        meneruskannya ke admin.
                    </div>
                )}
            </div>
            <div className="text-center text-nota-mini text-faint">
                Terima kasih sudah belanja di {invoice.toko}
            </div>
        </div>
    );
}

const rupiah = (n: number) => `Rp${n.toLocaleString('id-ID')}`;
const angka = (teks: string) => Number(teks.replace(/\D/g, '')) || 0;

/**
 * "Ubah item" (before ACC): the rows become fields; saving recounts the product total
 * and writes it to the order's history. Shipping stays as it was.
 */
function UbahItem({
    orderId,
    isian,
    onTutup,
}: {
    orderId: string;
    isian: BarisItem[];
    onTutup: () => void;
}) {
    const form = useForm<{ item: BarisItem[] }>({ item: isian });
    const galat = Object.values(form.errors)[0];
    const jumlah = form.data.item.reduce(
        (n, b) => n + angka(b.qty) * angka(b.harga),
        0,
    );

    const ubah = (i: number, isi: Partial<BarisItem>) =>
        form.setData(
            'item',
            form.data.item.map((b, j) => (j === i ? { ...b, ...isi } : b)),
        );

    return (
        <div className="flex flex-col gap-2 px-[18px] py-3">
            {/* phones: the rows scroll sideways instead of squeezing the fields */}
            <div className="flex flex-col gap-2 max-md:overflow-x-auto">
                <div className="grid grid-cols-[minmax(0,1fr)_120px_70px_110px_28px] gap-2 text-caption font-medium text-muted max-md:min-w-[480px]">
                    <span>Produk</span>
                    <span>Harga satuan</span>
                    <span>Jumlah</span>
                    <span className="text-right">Subtotal</span>
                    <span />
                </div>
                {form.data.item.map((b, i) => (
                    <div
                        key={i}
                        className="grid grid-cols-[minmax(0,1fr)_120px_70px_110px_28px] items-center gap-2 max-md:min-w-[480px]"
                    >
                        <Input
                            aria-label={`Nama item ${i + 1}`}
                            value={b.nama}
                            onChange={(e) => ubah(i, { nama: e.target.value })}
                            className="h-8"
                        />
                        <Input
                            aria-label={`Harga item ${i + 1}`}
                            inputMode="numeric"
                            value={b.harga}
                            onChange={(e) => ubah(i, { harga: e.target.value })}
                            className="h-8"
                        />
                        <Input
                            aria-label={`Jumlah item ${i + 1}`}
                            inputMode="numeric"
                            value={b.qty}
                            onChange={(e) => ubah(i, { qty: e.target.value })}
                            className="h-8"
                        />
                        <span className="text-right text-body">
                            {rupiah(angka(b.qty) * angka(b.harga))}
                        </span>
                        <button
                            type="button"
                            aria-label={`Hapus item ${i + 1}`}
                            onClick={() =>
                                form.setData(
                                    'item',
                                    form.data.item.filter((_, j) => j !== i),
                                )
                            }
                            className="flex size-7 cursor-pointer items-center justify-center border-none bg-transparent text-muted"
                        >
                            <Ikon nama="tutup" ukuran={15} />
                        </button>
                    </div>
                ))}
            </div>
            <div className="flex items-center gap-2 pt-1 max-md:flex-wrap">
                <Button
                    varian="link"
                    onClick={() =>
                        form.setData('item', [
                            ...form.data.item,
                            { nama: '', qty: '1', harga: '' },
                        ])
                    }
                >
                    <Ikon nama="tambah" ukuran={15} tebal={2} />
                    Tambah item
                </Button>
                <span className="grow text-right text-body font-semibold">
                    Total produk baru {rupiah(jumlah)}
                </span>
            </div>
            {galat && (
                <span role="alert" className="text-caption text-red-text">
                    {galat}
                </span>
            )}
            <div className="flex gap-2">
                <Button
                    varian="primary"
                    ukuran="sm"
                    aria-disabled={form.processing}
                    onClick={() =>
                        form.put(ruteItem.url(orderId), {
                            preserveScroll: true,
                            onSuccess: onTutup,
                        })
                    }
                >
                    Simpan item
                </Button>
                <Button ukuran="sm" onClick={onTutup}>
                    Batal
                </Button>
            </div>
        </div>
    );
}
