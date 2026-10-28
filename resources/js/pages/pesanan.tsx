import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { Jendela } from '@/components/ui/jendela';
import { PageHeader } from '@/components/ui/page-header';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { sedangMengetik } from '@/components/ui/topbar';
import { Halaman } from '@/components/ui/halaman';
import type { InfoHalaman } from '@/components/ui/halaman';
import { cn } from '@/lib/utils';
import { pelanggan, pesanan } from '@/routes';
import {
    aksi as perintah,
    catat,
    detail as halamanDetail,
    ekspor,
    kirimUlangInvoice,
} from '@/routes/pesanan';

type StatusOrder = { label: string; nada: Nada };

type Baris = {
    id: string;
    tanggal: string;
    nama: string;
    tipe: string | null;
    ringkas: string;
    total: string;
    bayar: string | null;
    status: StatusOrder;
    bukti: boolean;
    dobel: boolean;
};

type Aksi = {
    kode: string;
    boleh: boolean;
    /** ACC on an order already ACC'd: on once a new shipping cost is typed (revisi ongkir) */
    bolehDenganOngkir: boolean;
    /** The tooltip: what the command does, or why it does not apply. */
    judul: string;
    gaya: 'utama' | 'bahaya';
};

type Detail = {
    id: string;
    status: StatusOrder;
    nama: string;
    tipe: string | null;
    dibuat: string;
    customerId: number | null;
    langkah: {
        label: string;
        selesai: boolean;
        kini: boolean;
        mati: boolean;
        /** when this step happened; null while it has not */
        waktu: string | null;
    }[];
    item: { nama: string; qty: number; subtotal: string }[];
    produk: string;
    ongkir: string;
    packing: string | null;
    kurir: string | null;
    total: string;
    penerima: string;
    hp: string;
    alamat: string;
    metode: string;
    berat: string;
    invoice: string;
    resi: string;
    adaInvoice: boolean;
    bukti: boolean;
    buktiUrl: string | null;
    dobel: boolean;
    riwayat: { waktu: string; teks: string }[];
    aksi: Aksi[];
    /** "Lainnya": TUTUP */
    lainnya: Aksi[];
};

type Props = {
    tab: { kode: string; label: string; n: number }[];
    aktifTab: string;
    urut: string;
    halaman: InfoHalaman;
    baris: Baris[];
    detail: Detail | null;
};

const KOLOM = 'grid-cols-[132px_minmax(0,1fr)_132px_128px]';

/** Query string for the open tab and order ("semua" is the default tab). */
const query = (
    tab: string,
    order?: string,
    urut?: string,
    halaman?: number,
) => ({
    tab: tab === 'semua' ? undefined : tab,
    order,
    urut: urut && urut !== 'status' ? urut : undefined,
    halaman: halaman && halaman > 1 ? halaman : undefined,
});

const URUTAN: [string, string][] = [
    ['status', 'Status (berjalan dulu)'],
    ['terbaru', 'Terbaru'],
    ['terlama', 'Terlama'],
    ['total', 'Total terbesar'],
];

export default function Pesanan({
    tab,
    aktifTab,
    urut,
    halaman,
    baris,
    detail,
}: Props) {
    const [dipilih, setDipilih] = useState(detail?.id);
    const { url, props } = usePage();
    // ?order= opens the order in a pop-up over the list
    const lihatDetail = new URLSearchParams(url.split('?')[1] ?? '').has(
        'order',
    );

    // orders from the chatbot and status changes from WhatsApp appear by themselves
    usePoll(10000, { only: ['tab', 'baris', 'detail', 'panel'] });

    const pilih = (id: string) => {
        setDipilih(id);
        router.get(
            pesanan.url({ query: query(aktifTab, id) }),
            {},
            {
                only: ['detail'],
                preserveState: true,
                preserveScroll: true,
                // the first order opens as a step the browser's Back undoes; moving
                // between orders (J / K) replaces it
                replace: lihatDetail,
            },
        );
    };

    const keDaftar = () =>
        router.get(
            pesanan.url({ query: query(aktifTab) }),
            {},
            { only: ['detail'], preserveState: true, preserveScroll: true },
        );

    // J / K: next or previous order
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

            const sekarang = dipilih ?? detail?.id;
            const i = baris.findIndex((b) => b.id === sekarang);
            const ke =
                baris[
                    Math.min(
                        baris.length - 1,
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
        <AppLayout active="orders" penuhLayar>
            <Head title="Pesanan" />
            <PageHeader
                rapat
                judul="Pesanan"
                sub="Perintah order dijalankan dari sini, dengan urutan yang sama seperti workflow"
                aksi={
                    // each button only for the roles its page lets through
                    <>
                        {props.panel?.akses.balas && (
                            <Button
                                className="px-3"
                                onClick={() =>
                                    window.location.assign(
                                        ekspor.url({ query: query(aktifTab) }),
                                    )
                                }
                            >
                                <Ikon nama="unduh" ukuran={15} tebal={2} />
                                Ekspor CSV
                            </Button>
                        )}
                        {props.panel?.akses.catat && (
                            <ButtonLink
                                href={catat.url()}
                                varian="primary"
                                className="border-0"
                            >
                                <Ikon nama="tambah" ukuran={15} tebal={2} />
                                Catat order manual
                            </ButtonLink>
                        )}
                    </>
                }
            />
            <IsiHalaman className="gap-4 pb-6">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-caption text-muted">Status</span>
                    <Select
                        aria-label="Filter status"
                        className="h-9 w-auto max-md:h-11 max-md:grow"
                        value={aktifTab}
                        onChange={(e) =>
                            router.get(
                                pesanan.url({
                                    // the selected order stays open
                                    query: query(
                                        e.target.value,
                                        detail?.id,
                                        urut,
                                    ),
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {tab.map((t) => (
                            <option key={t.kode} value={t.kode}>
                                {t.label} ({t.n})
                            </option>
                        ))}
                    </Select>
                    <span className="pl-2 text-caption text-muted max-md:pl-0">
                        Urutkan
                    </span>
                    <Select
                        aria-label="Urutkan pesanan"
                        className="h-9 w-auto max-md:h-11 max-md:grow"
                        value={urut}
                        onChange={(e) =>
                            router.get(
                                pesanan.url({
                                    query: query(
                                        aktifTab,
                                        undefined,
                                        e.target.value,
                                    ),
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        {URUTAN.map(([nilai, label]) => (
                            <option key={nilai} value={nilai}>
                                {label}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="flex min-h-0 grow gap-5">
                    <section
                        aria-label="Daftar pesanan"
                        className="flex min-w-0 grow flex-col"
                    >
                        <div className="flex min-h-0 grow flex-col overflow-hidden rounded-card border border-line bg-surface">
                            <div
                                className={cn(
                                    'grid gap-3 border-b border-line-softer bg-subtle px-4 py-3 text-caption font-medium text-muted max-md:hidden',
                                    KOLOM,
                                )}
                            >
                                <span>Order</span>
                                <span>Customer &amp; item</span>
                                <span className="text-right">
                                    Total · bayar
                                </span>
                                <span>Status</span>
                            </div>
                            <div className="grow overflow-auto">
                                {baris.map((b) => (
                                    <BarisPesanan
                                        key={b.id}
                                        baris={b}
                                        dipilih={
                                            lihatDetail &&
                                            b.id === (dipilih ?? detail?.id)
                                        }
                                        onPilih={() => pilih(b.id)}
                                    />
                                ))}
                                {baris.length === 0 && (
                                    <div className="p-10 text-center text-body text-muted">
                                        Tidak ada pesanan di status ini.
                                    </div>
                                )}
                            </div>
                            <Halaman
                                halaman={halaman}
                                onPindah={(ke) =>
                                    router.get(
                                        pesanan.url({
                                            query: query(
                                                aktifTab,
                                                undefined,
                                                urut,
                                                ke,
                                            ),
                                        }),
                                        {},
                                        { preserveState: true },
                                    )
                                }
                            />
                        </div>
                    </section>
                    {detail && (
                        <Jendela
                            buka={lihatDetail}
                            judul={`Detail pesanan ${detail.id}`}
                            onTutup={keDaftar}
                            className="w-[min(600px,calc(100vw-32px))]"
                            polos
                        >
                            <PanelDetail
                                key={detail.id}
                                detail={detail}
                                onTutup={keDaftar}
                            />
                        </Jendela>
                    )}
                </div>
            </IsiHalaman>
        </AppLayout>
    );
}

function BarisPesanan({
    baris,
    dipilih,
    onPilih,
}: {
    baris: Baris;
    dipilih: boolean;
    onPilih: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onPilih}
            aria-pressed={dipilih}
            className={cn(
                'grid w-full cursor-pointer items-center gap-3 border-0 border-b border-line-softer px-4 py-3 text-ink',
                KOLOM,
                // phones: order + total on top, customer and status under them
                'max-md:grid-cols-[minmax(0,1fr)_auto] max-md:gap-y-1.5',
                dipilih
                    ? 'bg-selected shadow-[inset_3px_0_0_var(--color-primary)]'
                    : 'bg-surface hover:bg-subtle',
                'motion-safe:transition-colors motion-safe:duration-150',
            )}
        >
            <span className="flex flex-col gap-[3px] text-left">
                <span className="font-mono text-caption font-semibold">
                    {baris.id}
                </span>
                <span className="text-tag text-muted">{baris.tanggal}</span>
            </span>
            <span className="flex min-w-0 flex-col gap-[3px] text-left max-md:order-3 max-md:col-span-2">
                <span className="truncate text-body font-semibold">
                    {baris.nama}{' '}
                    {baris.tipe && (
                        <span className="text-caption font-normal text-muted">
                            · {baris.tipe}
                        </span>
                    )}
                </span>
                <span className="truncate text-caption text-ink-3">
                    {baris.ringkas}
                </span>
            </span>
            <span className="flex min-w-0 flex-col items-end gap-[3px] text-right max-md:order-2">
                <span className="text-body font-semibold whitespace-nowrap">
                    {baris.total}
                </span>
                <span className="max-w-full truncate text-tag text-muted">
                    {baris.bayar}
                </span>
            </span>
            <span className="flex flex-col items-start gap-1 max-md:order-4 max-md:col-span-2 max-md:flex-row max-md:flex-wrap max-md:items-center">
                <Status nada={baris.status.nada}>{baris.status.label}</Status>
                {baris.bukti && (
                    <span className="pl-3 text-caption font-medium whitespace-nowrap text-green-text">
                        Bukti transfer masuk
                    </span>
                )}
                {baris.dobel && (
                    <span className="pl-3 text-caption font-medium whitespace-nowrap text-amber-text">
                        Mungkin dobel
                    </span>
                )}
            </span>
        </button>
    );
}

/**
 * The grid's eighth button: TUTUP, once a WhatsApp command only. Opens
 * upwards; closes on a click outside or Esc.
 */
function MenuLainnya({
    aksi,
    onPilih,
}: {
    aksi: Aksi[];
    onPilih: (kode: string) => void;
}) {
    const [buka, setBuka] = useState(false);
    const wadah = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!buka) {
            return;
        }

        const tutup = (e: MouseEvent | KeyboardEvent) => {
            if (
                e instanceof KeyboardEvent
                    ? e.key === 'Escape'
                    : !wadah.current?.contains(e.target as Node)
            ) {
                setBuka(false);
            }
        };

        document.addEventListener('mousedown', tutup);
        document.addEventListener('keydown', tutup);

        return () => {
            document.removeEventListener('mousedown', tutup);
            document.removeEventListener('keydown', tutup);
        };
    }, [buka]);

    return (
        <div ref={wadah} className="relative">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={buka}
                onClick={() => setBuka(!buka)}
                className="flex h-9 w-full cursor-pointer items-center justify-center gap-1 rounded-control border border-line-strong bg-surface text-caption font-semibold whitespace-nowrap text-ink hover:bg-subtle motion-safe:transition-colors"
            >
                Lainnya
                <Ikon nama="turun" ukuran={14} />
            </button>
            {buka && (
                <div
                    role="menu"
                    className="absolute right-0 bottom-11 z-10 flex w-64 flex-col rounded-control border border-line bg-surface py-1 motion-safe:animate-[muncul_120ms_ease-out]"
                >
                    {aksi.map((a) => (
                        <button
                            key={a.kode}
                            type="button"
                            role="menuitem"
                            disabled={!a.boleh}
                            onClick={() => {
                                setBuka(false);
                                onPilih(a.kode);
                            }}
                            className="flex w-full cursor-pointer flex-col gap-0.5 border-none bg-transparent px-3 py-2 text-left hover:bg-subtle disabled:cursor-not-allowed disabled:hover:bg-transparent"
                        >
                            <span
                                className={cn(
                                    'text-caption font-semibold',
                                    a.boleh ? 'text-red-text' : 'text-faint',
                                )}
                            >
                                {a.kode}
                            </span>
                            <span className="text-tag text-muted">
                                {a.judul}
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

const kelasTombolInvoice =
    'flex h-9 grow cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface text-caption font-semibold text-ink no-underline hover:bg-subtle motion-safe:transition-colors';

function PanelDetail({
    detail,
    onTutup,
}: {
    detail: Detail;
    onTutup: () => void;
}) {
    const [modalAksi, setModalAksi] = useState<{
        kode: string;
        judul: string;
        deskripsi: string;
        labelInput?: string;
        placeholderInput?: string;
        wajibInput?: boolean;
        tombolTeks: string;
        varian?: 'primary' | 'bahaya';
    } | null>(null);
    const [nilaiInput, setNilaiInput] = useState('');
    const [mengirimInvoice, setMengirimInvoice] = useState(false);
    const [galatInvoice, setGalatInvoice] = useState<string | null>(null);

    // n8n sends the same invoice PDF again, from the customer's bot number
    const kirimInvoice = () => {
        if (mengirimInvoice) {
            return;
        }

        router.post(
            kirimUlangInvoice.url(detail.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setMengirimInvoice(true),
                onFinish: () => setMengirimInvoice(false),
                onSuccess: () => setGalatInvoice(null),
                onError: (e) => setGalatInvoice(e.invoice ?? null),
            },
        );
    };

    const jalankan = (kode: string, catatanKirim = '') =>
        router.post(
            perintah.url(detail.id),
            { aksi: kode, catatan: catatanKirim },
            { preserveScroll: true },
        );

    const klikAksi = (a: Aksi) => {
        if (a.kode === 'KIRIM') {
            setNilaiInput('');
            setModalAksi({
                kode: 'KIRIM',
                judul: 'Kirim Pesanan',
                deskripsi:
                    'Tandai pesanan telah dikirim. Paket ekspedisi wajib disertai nomor resinya. Untuk kurir motor atau armada Bang Bana, boleh tulis nama kurirnya atau kosongkan.',
                labelInput: 'Nomor resi atau nama kurir',
                placeholderInput: 'Contoh: JNE0123456789',
                wajibInput: false,
                tombolTeks: 'Konfirmasi Kirim',
                varian: 'primary',
            });
            return;
        }

        if (a.kode === 'ACC' && a.bolehDenganOngkir) {
            setNilaiInput('');
            setModalAksi({
                kode: 'ACC',
                judul: 'Setujui Pesanan & Tentukan Ongkir',
                deskripsi:
                    'Pesanan ini belum memiliki biaya ongkos kirim. Masukkan nominal ongkir untuk menerbitkan invoice ke customer.',
                labelInput: 'Biaya Ongkir (Rp)',
                placeholderInput: 'Contoh: 25000',
                wajibInput: true,
                tombolTeks: 'ACC & Terbitkan Invoice',
                varian: 'primary',
            });
            return;
        }

        if (a.kode === 'TOLAK') {
            setNilaiInput('');
            setModalAksi({
                kode: 'TOLAK',
                judul: 'Tolak Pesanan Ini?',
                deskripsi:
                    'Pesanan akan ditolak dan dibatalkan. Masukkan alasan penolakan untuk dicatat dalam riwayat sistem.',
                labelInput: 'Alasan Penolakan (Wajib)',
                placeholderInput:
                    'Contoh: Stok varian habis, customer membatalkan...',
                wajibInput: true,
                tombolTeks: 'Tolak Pesanan',
                varian: 'bahaya',
            });
            return;
        }

        if (a.kode === 'BATAL') {
            setNilaiInput('');
            setModalAksi({
                kode: 'BATAL',
                judul: 'Batalkan Pesanan Ini?',
                deskripsi:
                    'Pesanan akan dibatalkan. Masukkan alasan pembatalan untuk dicatat dalam riwayat sistem.',
                labelInput: 'Alasan Pembatalan (Wajib)',
                placeholderInput:
                    'Contoh: Customer meminta pembatalan transaksi...',
                wajibInput: true,
                tombolTeks: 'Batalkan Pesanan',
                varian: 'bahaya',
            });
            return;
        }

        // Aksi langsung (LUNAS, SIAP, SELESAI, atau ACC yang sudah berongkir)
        jalankan(a.kode);
    };

    return (
        // the header stays put while the rest scrolls inside the pop-up
        <div className="flex min-h-0 flex-col">
            <div className="flex flex-col gap-2 border-b border-line-softer px-5 pt-4 pb-3">
                <div className="flex items-center gap-2">
                    <h2 className="m-0 grow font-mono text-panel font-semibold">
                        {detail.id}
                    </h2>
                    <Status nada={detail.status.nada}>
                        {detail.status.label}
                    </Status>
                    <button
                        type="button"
                        aria-label="Tutup detail pesanan"
                        onClick={onTutup}
                        className="-mr-1.5 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-ink-3 hover:bg-line-softer max-md:size-11"
                    >
                        <Ikon nama="tutup" ukuran={16} tebal={2} />
                    </button>
                </div>
                <div className="text-body text-ink-3">
                    <Link
                        href={pelanggan.url({
                            query: { pilih: detail.customerId ?? undefined },
                        })}
                        className="font-semibold no-underline"
                    >
                        {detail.nama}
                    </Link>
                    {detail.tipe && ` · ${detail.tipe}`} · dibuat{' '}
                    {detail.dibuat} ·{' '}
                    <Link
                        href={halamanDetail.url(detail.id)}
                        className="font-medium no-underline"
                    >
                        Detail
                    </Link>
                </div>
                {/* a trail, not tabs: a dot per step on one line, with when it happened */}
                <ol className="m-0 flex list-none gap-1 p-0 pt-1.5">
                    {detail.langkah.map((l, i) => (
                        <li
                            key={l.label}
                            aria-current={l.kini ? 'step' : undefined}
                            className="flex min-w-0 grow flex-col gap-1.5"
                        >
                            <span className="flex items-center gap-1">
                                <span
                                    className={cn(
                                        'size-2 shrink-0 rounded-full',
                                        l.mati
                                            ? 'bg-red-dot'
                                            : l.kini
                                              ? 'bg-primary ring-2 ring-primary-line'
                                              : l.selesai
                                                ? 'bg-primary'
                                                : 'bg-line-strong',
                                    )}
                                />
                                {i < detail.langkah.length - 1 && (
                                    <span
                                        className={cn(
                                            'h-px grow',
                                            l.mati
                                                ? 'bg-note-red-line'
                                                : l.selesai
                                                  ? 'bg-primary'
                                                  : 'bg-line',
                                        )}
                                    />
                                )}
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span
                                    className={cn(
                                        'truncate text-tag',
                                        l.kini
                                            ? 'font-semibold text-ink'
                                            : l.selesai
                                              ? 'font-medium text-ink-2'
                                              : 'font-medium text-muted',
                                    )}
                                >
                                    {l.label}
                                </span>
                                <span className="truncate text-tag text-faint tabular-nums">
                                    {l.waktu ?? '—'}
                                </span>
                            </span>
                        </li>
                    ))}
                </ol>
            </div>

            <div className="flex min-h-0 grow flex-col gap-4 overflow-auto px-5 py-4">
                <div className="flex flex-col gap-1.5">
                    <div className="text-caption font-semibold text-ink">
                        Item
                    </div>
                    {detail.item.map((it, i) => (
                        <div
                            key={i}
                            className="grid grid-cols-[minmax(0,1fr)_52px_104px] gap-2 text-body"
                        >
                            <span>{it.nama}</span>
                            <span className="text-right text-ink-3 tabular-nums">
                                ×{it.qty}
                            </span>
                            <span className="text-right font-semibold tabular-nums">
                                {it.subtotal}
                            </span>
                        </div>
                    ))}
                    <div className="my-1 h-px bg-line-softer" />
                    <div className="flex text-body text-ink-3">
                        <span className="grow">Total produk</span>
                        <span>{detail.produk}</span>
                    </div>
                    <div className="flex text-body text-ink-3">
                        <span className="grow">Ongkir · {detail.kurir}</span>
                        <span>{detail.ongkir}</span>
                    </div>
                    {detail.packing && (
                        <div className="flex text-body text-ink-3">
                            <span className="grow">Packing</span>
                            <span>{detail.packing}</span>
                        </div>
                    )}
                    <div className="flex text-panel font-semibold">
                        <span className="grow">Total tagihan</span>
                        <span>{detail.total}</span>
                    </div>
                </div>

                <div className="flex flex-col gap-1.5">
                    <div className="text-caption font-semibold text-ink">
                        Pengiriman &amp; pembayaran
                    </div>
                    <div className="grid grid-cols-2 gap-x-3.5 gap-y-2.5">
                        <Info label="Penerima">{detail.penerima}</Info>
                        <Info label="No. HP" mono>
                            {detail.hp}
                        </Info>
                        <Info label="Alamat" lebar>
                            {detail.alamat}
                        </Info>
                        <Info label="Metode bayar">{detail.metode}</Info>
                        <Info label="Berat">{detail.berat}</Info>
                        <Info label="Invoice" mono>
                            {detail.invoice}
                        </Info>
                        <Info label="No. resi" mono>
                            {detail.resi}
                        </Info>
                    </div>
                </div>

                {detail.adaInvoice && (
                    <div className="flex gap-2">
                        <Link
                            href={halamanDetail.url(detail.id)}
                            className={kelasTombolInvoice}
                        >
                            Lihat invoice PDF
                        </Link>
                        <button
                            type="button"
                            className={kelasTombolInvoice}
                            aria-disabled={mengirimInvoice}
                            onClick={kirimInvoice}
                        >
                            Kirim ulang ke customer
                        </button>
                    </div>
                )}
                {galatInvoice && (
                    <span role="alert" className="text-caption text-red-text">
                        {galatInvoice}
                    </span>
                )}

                {detail.bukti && (
                    <div className="flex items-center gap-3 rounded-control border border-line bg-surface p-2.5">
                        <div className="flex size-16 shrink-0 items-center justify-center rounded-chip bg-line-softer text-muted">
                            <Ikon nama="gambar" ukuran={22} />
                        </div>
                        <div className="flex flex-col gap-0.5 text-caption">
                            <span className="font-semibold text-green-text">
                                Bukti transfer diterima
                            </span>
                            <span className="text-ink-2">
                                Foto dari customer · cek lalu tekan LUNAS. Order
                                ini tidak dibatalkan otomatis oleh batas bayar.
                            </span>
                            {detail.buktiUrl && (
                                <a
                                    href={detail.buktiUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-semibold no-underline hover:underline"
                                >
                                    Buka foto
                                </a>
                            )}
                        </div>
                    </div>
                )}

                {detail.dobel && (
                    <div className="rounded-control border border-note-amber-line bg-note-amber-bg p-2.5 text-caption text-ink-2">
                        <strong className="font-semibold text-[#92400E]">
                            Mungkin dobel.
                        </strong>{' '}
                        Isinya sama persis dengan order yang sudah dibayar
                        sebelumnya. Cek dengan customer sebelum ACC.
                    </div>
                )}

                <div className="flex flex-col gap-1.5">
                    <div className="text-caption font-semibold text-ink">
                        Riwayat
                    </div>
                    {detail.riwayat.map((r, i) => (
                        <div key={i} className="flex gap-2.5 text-caption">
                            <span className="w-11 shrink-0 text-muted">
                                {r.waktu}
                            </span>
                            <span className="text-ink-2">{r.teks}</span>
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex flex-col gap-3 border-t border-line-softer bg-subtle px-[18px] pt-3.5 pb-4">
                {(() => {
                    const maju = detail.aksi.filter(
                        (a) =>
                            a.gaya === 'utama' &&
                            (a.boleh || a.bolehDenganOngkir),
                    );
                    const bahaya = detail.aksi.filter(
                        (a) => a.gaya === 'bahaya' && a.boleh,
                    );
                    const lainnya = detail.lainnya.filter((a) => a.boleh);

                    const tidakAdaAksi =
                        maju.length === 0 &&
                        bahaya.length === 0 &&
                        lainnya.length === 0;

                    if (tidakAdaAksi) {
                        return (
                            <div className="rounded-control border border-line bg-surface p-3 text-caption text-ink-2">
                                Pesanan saat ini berada pada status{' '}
                                <strong>{detail.status.label}</strong>. Tidak
                                ada tindakan admin lanjutan yang diperlukan.
                            </div>
                        );
                    }

                    const labelAksi = (
                        kode: string,
                        bolehDenganOngkir: boolean,
                    ) => {
                        switch (kode) {
                            case 'ACC':
                                return bolehDenganOngkir
                                    ? 'ACC (+ Ongkir)'
                                    : 'ACC Pesanan';
                            case 'LUNAS':
                                return 'Tandai Lunas';
                            case 'SIAP':
                                return 'Siap Diambil';
                            case 'KIRIM':
                                return 'Kirim Pesanan';
                            case 'SELESAI':
                                return 'Selesaikan Pesanan';
                            case 'TOLAK':
                                return 'Tolak Pesanan';
                            case 'BATAL':
                                return 'Batalkan Pesanan';
                            default:
                                return kode;
                        }
                    };

                    return (
                        <>
                            {maju.length > 0 && (
                                <div className="flex flex-col gap-1.5">
                                    <span className="text-tag font-semibold tracking-[0.04em] text-muted uppercase">
                                        Langkah berikutnya
                                    </span>
                                    <div className="flex flex-wrap gap-2">
                                        {maju.map((a) => (
                                            <button
                                                key={a.kode}
                                                type="button"
                                                title={a.judul}
                                                onClick={() => klikAksi(a)}
                                                className="inline-flex h-9 cursor-pointer items-center justify-center rounded-control border border-primary bg-primary px-3.5 text-caption font-semibold text-white hover:border-primary-ink hover:bg-primary-ink motion-safe:transition-colors"
                                            >
                                                {labelAksi(
                                                    a.kode,
                                                    a.bolehDenganOngkir,
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {(bahaya.length > 0 || lainnya.length > 0) && (
                                <div className="flex flex-wrap items-center gap-2 border-t border-line-soft pt-2">
                                    <span className="mr-auto text-tag font-semibold tracking-[0.04em] text-muted uppercase">
                                        Opsi lain
                                    </span>
                                    {bahaya.map((a) => (
                                        <button
                                            key={a.kode}
                                            type="button"
                                            title={a.judul}
                                            onClick={() => klikAksi(a)}
                                            className="h-8 cursor-pointer rounded-control border border-line-strong bg-surface px-3 text-caption font-semibold text-red-text hover:bg-note-red-bg motion-safe:transition-colors"
                                        >
                                            {labelAksi(a.kode, false)}
                                        </button>
                                    ))}
                                    {lainnya.length > 0 && (
                                        <MenuLainnya
                                            aksi={lainnya}
                                            onPilih={jalankan}
                                        />
                                    )}
                                </div>
                            )}
                        </>
                    );
                })()}

                {modalAksi && (
                    <div
                        className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 p-4 motion-safe:animate-[pudar_120ms_ease-out]"
                        onClick={() => setModalAksi(null)}
                    >
                        <div
                            role="dialog"
                            aria-modal="true"
                            aria-labelledby="judul-modal-aksi"
                            onClick={(e) => e.stopPropagation()}
                            onKeyDown={(e) => {
                                // Esc closes this box only, not the order around it
                                if (e.key === 'Escape') {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    setModalAksi(null);
                                }
                            }}
                            className="w-full max-w-[440px] rounded-card border border-line bg-surface p-5 font-sans text-ink shadow-lg"
                        >
                            <h3
                                id="judul-modal-aksi"
                                className="m-0 text-panel font-semibold text-ink"
                            >
                                {modalAksi.judul}
                            </h3>
                            <p className="mt-2 text-body leading-relaxed text-ink-2">
                                {modalAksi.deskripsi}
                            </p>
                            {modalAksi.labelInput && (
                                <div className="mt-4 flex flex-col gap-1.5">
                                    <label
                                        htmlFor="input-modal-aksi"
                                        className="text-tag font-semibold tracking-wider text-muted uppercase"
                                    >
                                        {modalAksi.labelInput}
                                    </label>
                                    <Input
                                        id="input-modal-aksi"
                                        autoFocus
                                        placeholder={modalAksi.placeholderInput}
                                        value={nilaiInput}
                                        onChange={(e) =>
                                            setNilaiInput(e.target.value)
                                        }
                                        className="h-10 rounded-control bg-surface px-3 text-body"
                                    />
                                </div>
                            )}
                            <div className="mt-5 flex justify-end gap-2.5">
                                <Button
                                    type="button"
                                    onClick={() => setModalAksi(null)}
                                >
                                    Batal
                                </Button>
                                <Button
                                    type="button"
                                    varian={
                                        modalAksi.varian === 'bahaya'
                                            ? 'danger'
                                            : 'primary'
                                    }
                                    disabled={
                                        modalAksi.wajibInput &&
                                        !nilaiInput.trim()
                                    }
                                    onClick={() => {
                                        const catatanKirim = nilaiInput.trim();
                                        const kode = modalAksi.kode;
                                        setModalAksi(null);
                                        setNilaiInput('');
                                        jalankan(kode, catatanKirim);
                                    }}
                                >
                                    {modalAksi.tombolTeks}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

function Info({
    label,
    mono = false,
    lebar = false,
    children,
}: {
    label: string;
    mono?: boolean;
    /** Alamat: the full width, normal weight. */
    lebar?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={cn('flex flex-col gap-0.5', lebar && 'col-span-2')}>
            <span className="text-tag text-muted">{label}</span>
            <span
                className={cn(
                    'text-body',
                    lebar ? 'leading-[1.45]' : 'font-semibold',
                    mono && 'font-mono',
                )}
            >
                {children}
            </span>
        </div>
    );
}
