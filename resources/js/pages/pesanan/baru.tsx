import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Checkbox, Field, Input } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { InputCari } from '@/components/ui/input-cari';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import { Avatar, KeyValue } from '@/components/ui/teks';
import { pesanan } from '@/routes';
import { catat, simpan } from '@/routes/pesanan';

type Customer = {
    id: number;
    inisial: string;
    nama: string;
    nomor: string | null;
    tipe: string;
    kota: string | null;
    nomorBot: string | null;
    /** An order still waiting for ACC, waiting for payment or paid. */
    orderBerjalan: string | null;
};

type Baris = { nama: string; qty: string; harga: string };

type Props = { customer: Customer | null };

const KOLOM =
    'grid grid-cols-[minmax(0,1fr)_84px_130px_120px_32px] gap-2.5 max-md:min-w-[540px]';

const barisKosong = (): Baris => ({ nama: '', qty: '', harga: '' });

/** "18500" → 18500; "18.500" → 18500. */
const angka = (teks: string) => Number(teks.replace(/\D/g, '')) || 0;

/** Typed prices are shown as "18.500". */
const ribuan = (teks: string) => {
    const digit = teks.replace(/\D/g, '');

    return digit ? Number(digit).toLocaleString('id-ID') : '';
};

const rupiah = (nilai: number) => 'Rp' + nilai.toLocaleString('id-ID');

export default function CatatOrder({ customer }: Props) {
    const form = useForm({
        customer_id: null as number | null,
        item: [barisKosong()],
        total: '',
        kurir: '',
        alamat: '',
        tambahan: false,
    });
    const [cari, setCari] = useState(
        () => new URLSearchParams(window.location.search).get('cari') ?? '',
    );
    const pertama = useRef(true);

    // "CATAT <nama>": look the customer up as the name is typed
    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(
            () =>
                router.get(
                    catat.url({ query: { cari: cari || undefined } }),
                    {},
                    {
                        only: ['customer'],
                        preserveState: true,
                        preserveScroll: true,
                        replace: true,
                    },
                ),
            250,
        );

        return () => clearTimeout(tunda);
    }, [cari]);

    const item = form.data.item;
    const terisi = item.filter((b) => b.nama.trim() !== '');
    const jumlah = item.reduce((n, b) => n + angka(b.qty) * angka(b.harga), 0);
    const total = form.data.total ? angka(form.data.total) : jumlah;
    const ongkir = Math.max(0, total - jumlah);

    const ubahBaris = (i: number, kolom: keyof Baris, nilai: string) =>
        form.setData(
            'item',
            item.map((b, j) =>
                j === i
                    ? {
                          ...b,
                          [kolom]:
                              kolom === 'harga'
                                  ? ribuan(nilai)
                                  : kolom === 'qty'
                                    ? nilai.replace(/\D/g, '')
                                    : nilai,
                      }
                    : b,
            ),
        );

    const catatOrder = () => {
        form.transform((data) => ({
            ...data,
            // the customer found for what is typed in the search field
            customer_id: customer?.id ?? null,
            item: data.item.map((b) => ({
                nama: b.nama,
                qty: angka(b.qty),
                harga: angka(b.harga),
            })),
            total: data.total ? angka(data.total) : null,
        }));
        form.post(simpan.url());
    };

    // the WhatsApp command the form stands for; an empty field reads "| |"
    const perintah = [
        `CATAT ${customer?.nama ?? cari}`,
        terisi
            .map((b) => `${b.nama} ${angka(b.qty)} x ${angka(b.harga)}`)
            .join('; '),
        form.data.total ? String(angka(form.data.total)) : '',
        form.data.kurir,
        form.data.alamat,
    ]
        .join(' | ')
        .replace(/\|\s+(?=\|)/g, '| ');

    const galatItem = Object.entries(form.errors).find(([kunci]) =>
        kunci.startsWith('item'),
    )?.[1];

    return (
        <AppLayout active="orders">
            <Head title="Catat order manual" />
            <PageHeader
                breadcrumb={[
                    { label: 'Pesanan', href: pesanan.url() },
                    { label: 'Catat order manual' },
                ]}
                judul="Catat order manual"
                sub="Untuk order dari telepon atau datang langsung. Sama dengan perintah CATAT di WhatsApp: order tercatat dulu, lalu ACC untuk mengirim invoice."
                aksi={
                    <>
                        <ButtonLink href={pesanan.url()} varian="ghost">
                            Batal
                        </ButtonLink>
                        <Button
                            varian="primary"
                            aria-disabled={form.processing}
                            onClick={catatOrder}
                        >
                            Catat order
                        </Button>
                    </>
                }
            />
            <IsiHalaman>
                <div className="grid grid-cols-[minmax(0,1fr)_380px] items-start gap-5 max-xl:grid-cols-1">
                    <div className="flex min-w-0 flex-col gap-5">
                        <Panel judul="Customer">
                            <div className="flex flex-col gap-3">
                                <InputCari
                                    id="cari-customer"
                                    ukuran="md"
                                    aria-label="Cari customer"
                                    placeholder="Cari nama customer yang pernah chat"
                                    value={cari}
                                    onChange={(e) => setCari(e.target.value)}
                                />
                                {customer && (
                                    <KartuCustomer customer={customer} />
                                )}
                                <span className="text-caption leading-[1.45] text-muted">
                                    Customer dicari dari nama di CRM, sama
                                    seperti CATAT &lt;nama&gt;. Kalau ia masih
                                    punya order Menunggu ACC, Menunggu bayar,
                                    atau Lunas, order tidak dicatat kecuali
                                    ditandai pesanan tambahan.
                                </span>
                                <Galat>{form.errors.customer_id}</Galat>
                            </div>
                        </Panel>

                        <Panel judul="Item">
                            {/* phones: the rows scroll sideways instead of squeezing the fields */}
                            <div className="max-md:overflow-x-auto">
                                <div
                                    className={`${KOLOM} pb-1.5 text-caption font-medium text-muted`}
                                >
                                    <span>Item</span>
                                    <span className="text-right">Qty</span>
                                    <span className="text-right">
                                        Harga satuan
                                    </span>
                                    <span className="text-right">Subtotal</span>
                                    <span />
                                </div>
                                {item.map((b, i) => (
                                    <div
                                        key={i}
                                        className={`${KOLOM} items-center border-b border-line-soft py-2`}
                                    >
                                        <Input
                                            type="text"
                                            aria-label={`Nama item ${i + 1}`}
                                            value={b.nama}
                                            onChange={(e) =>
                                                ubahBaris(
                                                    i,
                                                    'nama',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <Input
                                            type="text"
                                            inputMode="numeric"
                                            aria-label={`Qty item ${i + 1}`}
                                            className="text-right"
                                            value={b.qty}
                                            onChange={(e) =>
                                                ubahBaris(
                                                    i,
                                                    'qty',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <div className="relative">
                                            <Input
                                                type="text"
                                                inputMode="numeric"
                                                aria-label={`Harga item ${i + 1}`}
                                                className="pl-[30px] text-right"
                                                value={b.harga}
                                                onChange={(e) =>
                                                    ubahBaris(
                                                        i,
                                                        'harga',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <span className="absolute top-2.5 left-[11px] text-body text-muted">
                                                Rp
                                            </span>
                                        </div>
                                        <span className="text-right text-body font-semibold">
                                            {rupiah(
                                                angka(b.qty) * angka(b.harga),
                                            )}
                                        </span>
                                        <button
                                            type="button"
                                            aria-label="Hapus item"
                                            onClick={() =>
                                                form.setData(
                                                    'item',
                                                    item.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                            className="flex size-8 cursor-pointer items-center justify-center rounded-control border-0 bg-transparent text-muted"
                                        >
                                            <Ikon nama="hapus" />
                                        </button>
                                    </div>
                                ))}
                            </div>
                            <div className="flex items-center gap-3 pt-3 max-md:flex-wrap">
                                <Button
                                    varian="link"
                                    onClick={() =>
                                        form.setData('item', [
                                            ...item,
                                            barisKosong(),
                                        ])
                                    }
                                >
                                    <Ikon nama="tambah" ukuran={15} tebal={2} />
                                    Tambah item
                                </Button>
                                <span className="grow" />
                                <span className="text-caption text-muted">
                                    Harga diketik admin — tabel harga bertingkat
                                    hanya saran
                                </span>
                            </div>
                            <Galat>{galatItem}</Galat>
                            <div className="mt-3.5 grid grid-cols-[minmax(0,1fr)_220px] items-end gap-3 max-md:grid-cols-1">
                                <span className="text-caption leading-[1.45] text-muted">
                                    Total boleh dikosongkan, otomatis dijumlah
                                    dari item. Ongkir tidak dihitung untuk order
                                    manual — masukkan ke total bila perlu.
                                </span>
                                <Field label="Total (opsional)" htmlFor="total">
                                    <Input
                                        id="total"
                                        type="text"
                                        inputMode="numeric"
                                        placeholder={`${rupiah(jumlah)} (otomatis)`}
                                        value={form.data.total}
                                        onChange={(e) =>
                                            form.setData(
                                                'total',
                                                ribuan(e.target.value),
                                            )
                                        }
                                    />
                                    <Galat>{form.errors.total}</Galat>
                                </Field>
                            </div>
                        </Panel>

                        <Panel judul="Pengiriman & pembayaran">
                            <div className="grid grid-cols-[220px_minmax(0,1fr)] gap-3 max-md:grid-cols-1">
                                <Field label="Kurir (opsional)" htmlFor="kurir">
                                    <Input
                                        id="kurir"
                                        type="text"
                                        value={form.data.kurir}
                                        onChange={(e) =>
                                            form.setData(
                                                'kurir',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Alamat (opsional)"
                                    htmlFor="alamat"
                                >
                                    <Input
                                        id="alamat"
                                        type="text"
                                        value={form.data.alamat}
                                        onChange={(e) =>
                                            form.setData(
                                                'alamat',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                            <div className="mt-3.5 grid grid-cols-3 gap-4 max-md:grid-cols-2">
                                <KeyValue label="Metode bayar">
                                    Transfer bank
                                </KeyValue>
                                <KeyValue label="Status awal">
                                    Menunggu ACC
                                </KeyValue>
                                <KeyValue label="Dikirim lewat">
                                    {customer?.nomorBot ?? '—'}
                                </KeyValue>
                            </div>
                            <div className="mt-3.5">
                                <Checkbox
                                    label="Pesanan tambahan terpisah"
                                    sub="Sama dengan CATAT PAKSA — tetap dicatat walau customer masih punya order berjalan"
                                    checked={form.data.tambahan}
                                    onChange={(e) =>
                                        form.setData(
                                            'tambahan',
                                            e.target.checked,
                                        )
                                    }
                                />
                                <Galat>{form.errors.tambahan}</Galat>
                            </div>
                        </Panel>
                    </div>

                    <div className="flex flex-col gap-5">
                        <Panel judul="Ringkasan">
                            <div className="flex flex-col gap-2">
                                <div className="flex text-body text-ink-3">
                                    <span className="grow">
                                        {terisi.length} item ·{' '}
                                        {terisi.reduce(
                                            (n, b) => n + angka(b.qty),
                                            0,
                                        )}{' '}
                                        pcs
                                    </span>
                                    <span>{rupiah(jumlah)}</span>
                                </div>
                                <div className="flex text-body text-ink-3">
                                    <span className="grow">Ongkir</span>
                                    <span>{rupiah(ongkir)}</span>
                                </div>
                                <div className="flex border-t border-line-soft pt-2 text-judul font-semibold">
                                    <span className="grow">Total</span>
                                    <span>{rupiah(total)}</span>
                                </div>
                            </div>
                        </Panel>

                        <Panel judul="Setelah dicatat">
                            <div className="flex flex-col gap-2">
                                {[
                                    'Order tercatat dengan nomor ORD baru, status Menunggu ACC, catatan “dicatat manual oleh admin”.',
                                    'Customer belum dikirimi apa pun.',
                                    'Tekan ACC di halaman order: invoice PDF dibuat dan dikirim ke customer lewat nomor yang sama.',
                                ].map((teks, i) => (
                                    <div
                                        key={i}
                                        className="flex gap-2.5 text-body leading-[1.5] text-ink-2"
                                    >
                                        <span className="text-faint tabular-nums">
                                            {i + 1}
                                        </span>
                                        <span>{teks}</span>
                                    </div>
                                ))}
                            </div>
                        </Panel>

                        <div className="flex flex-col gap-1.5 rounded-control border border-line bg-subtle px-3.5 py-3">
                            <span className="text-caption font-semibold">
                                Perintah WhatsApp yang setara
                            </span>
                            <span className="font-mono text-caption leading-[1.6] text-ink">
                                {perintah}
                            </span>
                            <span className="text-caption text-muted">
                                Lalu: ACC ORD-xxx
                            </span>
                        </div>
                    </div>
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function KartuCustomer({ customer }: { customer: Customer }) {
    return (
        <div className="flex items-center gap-3 rounded-control border border-primary bg-selected px-3.5 py-3 max-md:flex-wrap">
            <Avatar inisial={customer.inisial} />
            <div className="flex min-w-0 grow basis-0 flex-col gap-0.5">
                <span className="text-panel font-semibold">
                    {customer.nama}
                </span>
                <span className="text-caption text-muted">
                    {customer.nomor && (
                        <>
                            <span className="font-mono text-caption font-normal whitespace-nowrap text-muted">
                                {customer.nomor}
                            </span>{' '}
                            ·{' '}
                        </>
                    )}
                    {[
                        customer.tipe,
                        customer.kota,
                        customer.nomorBot && `chat lewat ${customer.nomorBot}`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </div>
            {customer.orderBerjalan ? (
                <Status nada="amber">
                    Ada order berjalan · {customer.orderBerjalan}
                </Status>
            ) : (
                <Status nada="green">Tidak ada order berjalan</Status>
            )}
        </div>
    );
}

function Galat({ children }: { children?: ReactNode }) {
    return children ? (
        <span className="text-caption text-red-text">{children}</span>
    ) : null;
}
