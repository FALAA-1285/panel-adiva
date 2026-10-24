import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import type { ChangeEvent } from 'react';
import {
    IsianAngka,
    IsianKunci,
    IsianNomor,
    kelasIsian,
    kelasIsianPanjang,
} from '@/components/pengaturan/isian';
import { KartuPengaturan } from '@/components/pengaturan/kartu';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Ikon } from '@/components/ui/ikon';
import { InputKata } from '@/components/ui/input-kata';
import { PageHeader } from '@/components/ui/page-header';
import { cn } from '@/lib/utils';
import { prompt, tim } from '@/routes';
import { kabarWa, simpan } from '@/routes/pengaturan';

/** CONFIG_TOKO values on the page, by key (App\Http\Requests\SimpanPengaturanRequest::ISIAN). */
type Nilai = {
    ADMIN_WA: string;
    SARAH_WA: string;
    NAMA_TOKO: string;
    REKENING: string;
    BATAS_BAYAR_JAM: string;
    BATAS_BAYAR_PICKUP_JAM: string;
    BATAS_KONFIRMASI_JAM: string;
    CATATAN_INVOICE: string;
    LINK_ULASAN: string;
    AMBIL_TOKO_SAWOJAJAR: string;
    AMBIL_PABRIK_LAWANG: string;
    JAM_PESAN_OTOMATIS: string;
    HARI_LIBUR_OTOMATIS: string[];
    JEDA_FOLLOWUP_JAM: string;
    JEDA_KESAN_HARI: string;
    PACKING_PER_KG: string;
    PACKING_MINIMAL: string;
    PACKING_GRATIS_DI_ATAS: string;
    SESI_UTAMA: string;
    ARSIP_ORDER_HARI: string;
    ARSIP_CUSTOMER_HARI: string;
};

type KunciTeks = Exclude<keyof Nilai, 'HARI_LIBUR_OTOMATIS'>;

type Ai = {
    AI_MODEL_UTAMA: string;
    AI_MODEL_CADANGAN: string;
    AI_TEMPERATURE: string;
    AI_INGATAN_CHAT: string;
    AI_TUNGGU_GABUNG: string;
};

type Props = {
    disimpan: string | null;
    nilai: Nilai;
    ai: Ai;
    modelPilihan: string[];
    invoiceTerakhir: string | null;
    /** Notifikasi WhatsApp rows switched off */
    kabarWaMati: string[];
    staf: {
        id: number;
        nama: string;
        nomor: string | null;
        peran: string;
        diWhatsapp: string;
        semua: boolean;
    }[];
    nomorBot: {
        sesi: string;
        label: string;
        status: { label: string; nada: 'green' | 'red' } | null;
    }[];
    terakhirDicek: string | null;
    promptBagian: number | null;
    pj: string;
};

// Notifikasi WhatsApp: the panel sends a short WhatsApp for these (App\Support\KabarWa::BARIS);
// each row switches its kind on or off
const notifikasi = (pj: string) =>
    [
        ['order_baru', 'Order baru menunggu ACC'],
        ['order_karyawan', `Order karyawan → ${pj}`],
        ['butuh_admin', 'Customer butuh admin'],
        ['lampiran', 'Bukti transfer / lampiran'],
        ['balasan_gagal', 'Balasan atau AI gagal'],
        ['invoice_gagal', 'Invoice PDF gagal (dikirim versi teks)'],
        ['jeda_chat', 'Customer dijeda chat lagi'],
        ['jeda_kedaluwarsa', 'Jeda kedaluwarsa, bot lanjut'],
        ['jeda_error', 'Tabel jeda gagal dibaca'],
        ['dobel', 'Order kemungkinan dobel'],
        ['order_otomatis', 'Order dibatalkan / ditutup otomatis'],
        ['bukti_belum_dicek', 'Bukti transfer belum dicek'],
        ['nomor_putus', 'Nomor bot putus / tersambung lagi'],
        ['workflow_error', 'Workflow error'],
    ] as const;

const jadwal = [
    ['Pengingat daftar jeda', '09.00 WIB'],
    ['Laporan harian', '20.00 WIB'],
    ['Rekap arsip mingguan', 'Minggu 01.00'],
];

const warnaStatus = {
    green: ['text-green-text', 'bg-green-dot'],
    red: ['text-red-text', 'bg-red-dot'],
} as const;

/** Select on this page: 13px text, its own height and corners per field. */
const kelasPilihan =
    'box-border border border-line-strong bg-surface text-body text-ink';

type TabPengaturan =
    | 'semua'
    | 'kontak'
    | 'toko'
    | 'jam-kerja'
    | 'pengiriman'
    | 'bot-ai'
    | 'notifikasi';

export default function Pengaturan({
    disimpan,
    nilai,
    ai,
    modelPilihan,
    invoiceTerakhir,
    kabarWaMati,
    staf,
    nomorBot,
    terakhirDicek,
    promptBagian,
    pj,
}: Props) {
    const form = useForm({ ...nilai, ai });
    const [tabAktif, setTabAktif] = useState<TabPengaturan>('semua');
    const galat = form.errors as Record<string, string | undefined>;

    /** The first error of a field, including the errors of its list items. */
    const salah = (kunci: string) =>
        galat[kunci] ??
        Object.entries(galat).find(([k]) => k.startsWith(`${kunci}.`))?.[1];

    const teks = (kunci: KunciTeks) => ({
        value: form.data[kunci],
        onChange: (
            e: ChangeEvent<
                HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
            >,
        ) => form.setData(kunci, e.target.value),
    });

    const ubahAi = (kunci: keyof Ai, nilaiBaru: string) =>
        form.setData('ai', { ...form.data.ai, [kunci]: nilaiBaru });

    const simpanSemua = () =>
        form.put(simpan.url(), { preserveScroll: true });

    const adaKolom1 =
        tabAktif === 'semua' ||
        tabAktif === 'kontak' ||
        tabAktif === 'toko' ||
        tabAktif === 'jam-kerja';
    const adaKolom2 =
        tabAktif === 'semua' ||
        tabAktif === 'pengiriman' ||
        tabAktif === 'bot-ai' ||
        tabAktif === 'notifikasi';

    return (
        <AppLayout active="settings">
            <Head title="Pengaturan" />
            <div className="sticky top-0 z-20 bg-surface/95 backdrop-blur-xs">
                <PageHeader
                    judul="Pengaturan"
                    sub="Setiap isian tersimpan sebagai satu pengaturan toko. Adiva memakai nilai baru mulai pesan berikutnya."
                    aksi={
                        <>
                            {disimpan && (
                                <span className="text-body whitespace-nowrap text-muted">
                                    {disimpan}
                                </span>
                            )}
                            <Button
                                varian="primary"
                                className="border-0 shadow-xs"
                                aria-disabled={form.processing}
                                onClick={simpanSemua}
                            >
                                <Ikon nama="simpan" ukuran={15} tebal={2} />
                                Simpan perubahan
                            </Button>
                        </>
                    }
                    tab={[
                        {
                            label: 'Semua',
                            aktif: tabAktif === 'semua',
                            onClick: () => setTabAktif('semua'),
                        },
                        {
                            label: 'Kontak & Tim',
                            aktif: tabAktif === 'kontak',
                            onClick: () => setTabAktif('kontak'),
                        },
                        {
                            label: 'Toko & Pembayaran',
                            aktif: tabAktif === 'toko',
                            onClick: () => setTabAktif('toko'),
                        },
                        {
                            label: 'Jam Kerja',
                            aktif: tabAktif === 'jam-kerja',
                            onClick: () => setTabAktif('jam-kerja'),
                        },
                        {
                            label: 'Pengiriman',
                            aktif: tabAktif === 'pengiriman',
                            onClick: () => setTabAktif('pengiriman'),
                        },
                        {
                            label: 'Bot WA & AI',
                            aktif: tabAktif === 'bot-ai',
                            onClick: () => setTabAktif('bot-ai'),
                        },
                        {
                            label: 'Notifikasi & Arsip',
                            aktif: tabAktif === 'notifikasi',
                            onClick: () => setTabAktif('notifikasi'),
                        },
                    ]}
                />
            </div>

            <IsiHalaman
                className={cn(
                    'items-start gap-5 pb-8',
                    tabAktif === 'semua'
                        ? 'grid grid-cols-2 max-lg:grid-cols-1'
                        : 'flex max-w-4xl flex-col',
                )}
            >
                {adaKolom1 && (
                    <div
                        className={cn(
                            'flex flex-col gap-[18px]',
                            tabAktif !== 'semua' && 'w-full',
                        )}
                    >
                        {(tabAktif === 'semua' || tabAktif === 'kontak') && (
                            <KartuPengaturan judul="Kontak & tim">
                                <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                    <IsianKunci
                                        label="Nomor supervisor"
                                        kunci="ADMIN_WA"
                                        error={salah('ADMIN_WA')}
                                    >
                                        <IsianNomor
                                            nomor={form.data.ADMIN_WA}
                                            onUbah={(v) =>
                                                form.setData('ADMIN_WA', v)
                                            }
                                        />
                                    </IsianKunci>
                                    <IsianKunci
                                        label="PJ program karyawan"
                                        kunci="SARAH_WA"
                                        error={salah('SARAH_WA')}
                                    >
                                        <IsianNomor
                                            nomor={form.data.SARAH_WA}
                                            onUbah={(v) =>
                                                form.setData('SARAH_WA', v)
                                            }
                                            akhir={4}
                                        />
                                    </IsianKunci>
                                </div>
                                <div className="flex flex-col overflow-hidden rounded-card border border-line-softer max-md:overflow-x-auto">
                                    <div className="grid grid-cols-[minmax(0,1fr)_120px_100px_120px] gap-2.5 bg-subtle px-3 py-[9px] text-caption font-medium text-muted max-md:min-w-[560px]">
                                        <span>Staf</span>
                                        <span>Nomor</span>
                                        <span>Peran</span>
                                        <span>Di WhatsApp</span>
                                    </div>
                                    {staf.map((s) => (
                                        <div
                                            key={s.id}
                                            className="grid grid-cols-[minmax(0,1fr)_120px_100px_120px] items-center gap-2.5 border-t border-line-softer px-3 py-2.5 text-body max-md:min-w-[560px]"
                                        >
                                            <span className="font-medium">
                                                {s.nama}
                                            </span>
                                            <span className="font-mono text-caption">
                                                {s.nomor}
                                            </span>
                                            <span>{s.peran}</span>
                                            <span
                                                className={
                                                    s.semua
                                                        ? 'text-ink'
                                                        : 'text-ink-3'
                                                }
                                            >
                                                {s.diWhatsapp}
                                            </span>
                                        </div>
                                    ))}
                                    <Link
                                        href={tim.url()}
                                        className="box-content flex h-[38px] items-center justify-center border-t border-line-soft text-caption font-medium no-underline"
                                    >
                                        Kelola staf &amp; hak akses di Tim &amp;
                                        akses →
                                    </Link>
                                </div>
                                <div className="text-tag text-muted">
                                    Pesan dari nomor staf dibaca sebagai
                                    perintah admin, bukan chat customer. Login
                                    panel memakai akun terpisah per staf.
                                </div>
                            </KartuPengaturan>
                        )}

                        {(tabAktif === 'semua' || tabAktif === 'toko') && (
                            <>
                                <KartuPengaturan judul="Pembayaran & invoice">
                                    <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                        <IsianKunci
                                            label="Nama di invoice"
                                            kunci="NAMA_TOKO"
                                            error={salah('NAMA_TOKO')}
                                        >
                                            <input
                                                type="text"
                                                className={kelasIsian}
                                                {...teks('NAMA_TOKO')}
                                            />
                                        </IsianKunci>
                                        <IsianKunci
                                            label="Nomor invoice terakhir"
                                            kunci="INVOICE_TERAKHIR"
                                        >
                                            <div className="box-border flex h-10 items-center justify-between rounded-control border border-line bg-subtle px-3 font-mono text-body text-ink-3">
                                                {invoiceTerakhir}
                                                <span className="font-sans text-tag text-muted">
                                                    diisi otomatis
                                                </span>
                                            </div>
                                        </IsianKunci>
                                    </div>
                                    <IsianKunci
                                        label="Rekening tujuan"
                                        kunci="REKENING"
                                        error={salah('REKENING')}
                                    >
                                        <textarea
                                            rows={2}
                                            placeholder="[Bank] · [nomor rekening] · a.n. CV TKT Bana Food"
                                            className={kelasIsianPanjang}
                                            {...teks('REKENING')}
                                        />
                                    </IsianKunci>
                                    <div className="grid grid-cols-3 gap-3 max-md:grid-cols-1">
                                        {(
                                            [
                                                [
                                                    'Batas bayar (kirim)',
                                                    'BATAS_BAYAR_JAM',
                                                ],
                                                [
                                                    'Batas bayar (ambil)',
                                                    'BATAS_BAYAR_PICKUP_JAM',
                                                ],
                                                [
                                                    'Tutup bila belum ACC',
                                                    'BATAS_KONFIRMASI_JAM',
                                                ],
                                            ] as const
                                        ).map(([label, kunci]) => (
                                            <IsianKunci
                                                key={kunci}
                                                label={label}
                                                kunci={kunci}
                                                error={salah(kunci)}
                                            >
                                                <IsianAngka
                                                    satuan="jam kerja"
                                                    {...teks(kunci)}
                                                />
                                            </IsianKunci>
                                        ))}
                                    </div>
                                    <span className="text-caption leading-[1.45] text-muted">
                                        Semua dihitung dalam jam kerja. Customer
                                        diingatkan 1 jam kerja sebelum batas.
                                        Order yang sudah kirim bukti transfer
                                        tidak pernah dibatalkan otomatis; ambil
                                        sendiri yang bayar di tempat tidak punya
                                        batas.{' '}
                                        <Link
                                            href="/otomasi"
                                            className="font-medium no-underline"
                                        >
                                            Lihat alurnya
                                        </Link>
                                    </span>
                                    <IsianKunci
                                        label="Catatan di invoice"
                                        kunci="CATATAN_INVOICE"
                                        error={salah('CATATAN_INVOICE')}
                                    >
                                        <textarea
                                            rows={2}
                                            className={kelasIsianPanjang}
                                            {...teks('CATATAN_INVOICE')}
                                        />
                                    </IsianKunci>
                                    <IsianKunci
                                        label="Link ulasan"
                                        kunci="LINK_ULASAN"
                                        error={salah('LINK_ULASAN')}
                                    >
                                        <input
                                            type="url"
                                            placeholder="[tautan Google Maps / marketplace]"
                                            className={kelasIsian}
                                            {...teks('LINK_ULASAN')}
                                        />
                                    </IsianKunci>
                                </KartuPengaturan>

                                <KartuPengaturan judul="Pengambilan sendiri">
                                    {(
                                        [
                                            [
                                                'Toko Sawojajar — alamat & jam ambil',
                                                'AMBIL_TOKO_SAWOJAJAR',
                                            ],
                                            [
                                                'Pabrik Lawang — alamat & jam ambil',
                                                'AMBIL_PABRIK_LAWANG',
                                            ],
                                        ] as const
                                    ).map(([label, kunci]) => (
                                        <IsianKunci
                                            key={kunci}
                                            label={label}
                                            kunci={kunci}
                                            error={salah(kunci)}
                                        >
                                            <textarea
                                                rows={2}
                                                placeholder="[alamat lengkap] · [hari & jam ambil]"
                                                className={kelasIsianPanjang}
                                                {...teks(kunci)}
                                            />
                                        </IsianKunci>
                                    ))}
                                    <div className="text-tag text-muted">
                                        Dikirim ke customer saat admin menekan
                                        SIAP.
                                    </div>
                                </KartuPengaturan>
                            </>
                        )}

                        {(tabAktif === 'semua' || tabAktif === 'jam-kerja') && (
                            <KartuPengaturan
                                judul="Jam kerja & pesan otomatis"
                                rapat
                            >
                                <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                    <IsianKunci
                                        label="Jam kerja otomatis"
                                        kunci="JAM_PESAN_OTOMATIS"
                                        error={salah('JAM_PESAN_OTOMATIS')}
                                    >
                                        <input
                                            type="text"
                                            className={kelasIsian}
                                            {...teks('JAM_PESAN_OTOMATIS')}
                                        />
                                    </IsianKunci>
                                    <IsianKunci
                                        label="Hari libur"
                                        kunci="HARI_LIBUR_OTOMATIS"
                                        sebagai="div"
                                        error={salah('HARI_LIBUR_OTOMATIS')}
                                    >
                                        <InputKata
                                            varian="pengaturan"
                                            kata={form.data.HARI_LIBUR_OTOMATIS}
                                            onUbah={(kata) =>
                                                form.setData(
                                                    'HARI_LIBUR_OTOMATIS',
                                                    kata,
                                                )
                                            }
                                            aria-label="Tambah hari libur"
                                            placeholder="Tambah tanggal…"
                                            className="border-line-strong"
                                            inputClassName="w-[90px] min-w-0"
                                        />
                                    </IsianKunci>
                                </div>
                                <span className="text-caption leading-[1.45] text-muted">
                                    Di luar jam kerja dan hari libur tidak ada
                                    pengingat bayar dan order tidak ditutup;
                                    batas bayar juga berhenti dihitung. Tanggal
                                    merah ditulis seperti 2026-12-25. Sapaan
                                    prospek tetap boleh hari Minggu, tapi tidak
                                    dikirim di luar jam kerja.
                                </span>
                                <div className="border-t border-line-softer" />
                                <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                    <IsianKunci
                                        label="Sapa ulang prospek setelah"
                                        kunci="JEDA_FOLLOWUP_JAM"
                                        error={salah('JEDA_FOLLOWUP_JAM')}
                                    >
                                        <IsianAngka
                                            satuan="jam diam"
                                            ukuran="h-[38px] w-[90px] rounded-control"
                                            satuanClassName="text-body"
                                            {...teks('JEDA_FOLLOWUP_JAM')}
                                        />
                                    </IsianKunci>
                                    <IsianKunci
                                        label="Tanya kesan setelah SELESAI"
                                        kunci="JEDA_KESAN_HARI"
                                        error={salah('JEDA_KESAN_HARI')}
                                    >
                                        <IsianAngka
                                            satuan="hari"
                                            ukuran="h-[38px] w-[90px] rounded-control"
                                            satuanClassName="text-body"
                                            {...teks('JEDA_KESAN_HARI')}
                                        />
                                    </IsianKunci>
                                </div>
                                <span className="text-caption leading-[1.45] text-muted">
                                    Prospek disapa satu kali saja lalu berhenti
                                    sampai customer membalas lagi. Tidak ada
                                    pengingat restock. Tahap Tidak minat,
                                    opt-out, dan customer yang dijeda dilewati.{' '}
                                    <Link
                                        href="/otomasi"
                                        className="font-medium no-underline"
                                    >
                                        Template pesan
                                    </Link>
                                </span>
                            </KartuPengaturan>
                        )}
                    </div>
                )}

                {adaKolom2 && (
                    <div
                        className={cn(
                            'flex flex-col gap-[18px]',
                            tabAktif !== 'semua' && 'w-full',
                        )}
                    >
                        {(tabAktif === 'semua' ||
                            tabAktif === 'pengiriman') && (
                            <KartuPengaturan judul="Pengiriman & packing">
                                <span className="text-caption leading-[1.45] text-muted">
                                    Paket ekspedisi dikirim dengan ongkir COD:
                                    ongkir dibayar customer ke kurir dan tidak
                                    ikut ditagih. Yang ditransfer hanya produk
                                    dan biaya packing di bawah ini. Kosongkan
                                    kalau tidak ada biaya packing.
                                </span>
                                <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                    <IsianKunci
                                        label="Packing per kg"
                                        kunci="PACKING_PER_KG"
                                        error={salah('PACKING_PER_KG')}
                                    >
                                        <input
                                            type="number"
                                            min={0}
                                            inputMode="numeric"
                                            className={kelasIsian}
                                            {...teks('PACKING_PER_KG')}
                                        />
                                    </IsianKunci>
                                    <IsianKunci
                                        label="Packing minimal"
                                        kunci="PACKING_MINIMAL"
                                        error={salah('PACKING_MINIMAL')}
                                    >
                                        <input
                                            type="number"
                                            min={0}
                                            inputMode="numeric"
                                            className={kelasIsian}
                                            {...teks('PACKING_MINIMAL')}
                                        />
                                    </IsianKunci>
                                </div>
                                <IsianKunci
                                    label="Packing gratis untuk belanja produk mulai"
                                    kunci="PACKING_GRATIS_DI_ATAS"
                                    error={salah('PACKING_GRATIS_DI_ATAS')}
                                >
                                    <input
                                        type="number"
                                        min={0}
                                        inputMode="numeric"
                                        className={kelasIsian}
                                        {...teks('PACKING_GRATIS_DI_ATAS')}
                                    />
                                </IsianKunci>
                            </KartuPengaturan>
                        )}

                        {(tabAktif === 'semua' || tabAktif === 'bot-ai') && (
                            <>
                                <KartuPengaturan
                                    judul="Nomor WhatsApp bot"
                                    kunci="DAFTAR_NOMOR_BOT"
                                    rapat
                                >
                                    <span className="text-caption leading-[1.45] text-muted">
                                        Semua nomor masuk ke pintu yang sama.
                                        Customer selalu dibalas dari nomor yang
                                        ia gunakan, termasuk invoice dan
                                        pengingat.
                                    </span>
                                    <div className="overflow-hidden rounded-control border border-line max-md:overflow-x-auto">
                                        <div className="grid grid-cols-[150px_minmax(0,1fr)_100px] gap-2.5 bg-subtle px-3 py-2 text-caption font-medium text-muted max-md:min-w-[440px]">
                                            <span>Sesi WAHA</span>
                                            <span>Label</span>
                                            <span>Status</span>
                                        </div>
                                        {nomorBot.map((n) => (
                                            <div
                                                key={n.sesi}
                                                className="grid grid-cols-[150px_minmax(0,1fr)_100px] items-center gap-2.5 border-t border-line-soft px-3 py-[9px] text-body max-md:min-w-[440px]"
                                            >
                                                <span className="font-mono text-caption">
                                                    {n.sesi}
                                                </span>
                                                <span>{n.label}</span>
                                                {n.status ? (
                                                    <span
                                                        className={cn(
                                                            'inline-flex items-center gap-1.5 text-caption',
                                                            warnaStatus[
                                                                n.status.nada
                                                            ][0],
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                'size-1.5 rounded-full',
                                                                warnaStatus[
                                                                    n.status
                                                                        .nada
                                                                ][1],
                                                            )}
                                                        />
                                                        {n.status.label}
                                                    </span>
                                                ) : (
                                                    <span className="text-caption text-muted">
                                                        —
                                                    </span>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                    <span className="text-caption leading-[1.45] text-muted">
                                        Dipantau tiap 15 menit. Kalau satu nomor
                                        putus 2× berturut-turut, supervisor
                                        dikabari lewat nomor lain yang masih
                                        tersambung, lalu dikabari lagi saat
                                        tersambung.
                                        {terakhirDicek &&
                                            ` Terakhir dicek ${terakhirDicek}.`}
                                    </span>
                                    <label className="flex items-center gap-3">
                                        <span className="flex grow flex-col gap-0.5">
                                            <span className="text-body font-semibold">
                                                Nomor untuk pesan terjadwal
                                            </span>
                                            <span className="font-mono text-key text-muted">
                                                SESI_UTAMA
                                            </span>
                                        </span>
                                        <select
                                            className={cn(
                                                kelasPilihan,
                                                'h-9 w-[170px] rounded-control px-2',
                                            )}
                                            {...teks('SESI_UTAMA')}
                                        >
                                            {nomorBot.map((n) => (
                                                <option
                                                    key={n.sesi}
                                                    value={n.sesi}
                                                >
                                                    {n.label}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                </KartuPengaturan>

                                <KartuPengaturan
                                    judul="AI & perilaku bot"
                                    sub="Konfigurasi model AI, gaya respon (suhu), dan parameter ingatan bot Adiva."
                                >
                                    <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                        {(
                                            [
                                                [
                                                    'Model utama',
                                                    'AI_MODEL_UTAMA',
                                                ],
                                                [
                                                    'Model cadangan',
                                                    'AI_MODEL_CADANGAN',
                                                ],
                                            ] as const
                                        ).map(([label, kunci]) => (
                                            <IsianKunci
                                                key={kunci}
                                                label={label}
                                                error={salah(`ai.${kunci}`)}
                                            >
                                                <select
                                                    value={form.data.ai[kunci]}
                                                    onChange={(e) =>
                                                        ubahAi(
                                                            kunci,
                                                            e.target.value,
                                                        )
                                                    }
                                                    className={cn(
                                                        kelasPilihan,
                                                        'h-10 rounded-[9px] px-2.5',
                                                    )}
                                                >
                                                    {modelPilihan.map((m) => (
                                                        <option
                                                            key={m}
                                                            value={m}
                                                        >
                                                            {m}
                                                        </option>
                                                    ))}
                                                </select>
                                            </IsianKunci>
                                        ))}
                                    </div>
                                    <div className="grid grid-cols-3 gap-3 max-md:grid-cols-1">
                                        <IsianKunci
                                            label="Temperature"
                                            error={salah('ai.AI_TEMPERATURE')}
                                        >
                                            <span className="flex items-center gap-2">
                                                <input
                                                    type="range"
                                                    min={0}
                                                    max={1}
                                                    step={0.1}
                                                    value={
                                                        form.data.ai
                                                            .AI_TEMPERATURE
                                                    }
                                                    onChange={(e) =>
                                                        ubahAi(
                                                            'AI_TEMPERATURE',
                                                            e.target.value,
                                                        )
                                                    }
                                                    // m-0.5: Chrome's default 2px margin on range inputs, which preflight removes
                                                    className="m-0.5 grow accent-primary"
                                                />
                                                <span className="text-body font-bold">
                                                    {Number(
                                                        form.data.ai
                                                            .AI_TEMPERATURE,
                                                    )
                                                        .toFixed(1)
                                                        .replace('.', ',')}
                                                </span>
                                            </span>
                                        </IsianKunci>
                                        {(
                                            [
                                                [
                                                    'Ingatan chat',
                                                    'AI_INGATAN_CHAT',
                                                    'pesan',
                                                ],
                                                [
                                                    'Tunggu gabung',
                                                    'AI_TUNGGU_GABUNG',
                                                    'detik',
                                                ],
                                            ] as const
                                        ).map(([label, kunci, satuan]) => (
                                            <IsianKunci
                                                key={kunci}
                                                label={label}
                                                error={salah(`ai.${kunci}`)}
                                            >
                                                <IsianAngka
                                                    satuan={satuan}
                                                    ukuran="h-[38px] w-[72px] rounded-[9px] px-2.5"
                                                    satuanClassName="text-caption"
                                                    value={form.data.ai[kunci]}
                                                    onChange={(e) =>
                                                        ubahAi(
                                                            kunci,
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </IsianKunci>
                                        ))}
                                    </div>
                                    <div className="flex items-center gap-3 rounded-card border border-line-softer px-3.5 py-3 max-md:flex-wrap">
                                        <div className="flex grow flex-col gap-0.5 max-md:basis-full">
                                            <span className="text-body font-semibold">
                                                Prompt sistem Adiva
                                            </span>
                                            <span className="text-caption text-ink-3">
                                                {promptBagian !== null &&
                                                    `${promptBagian} bagian (0–${promptBagian - 1}) · `}
                                                identitas, gaya bahasa, jalur
                                                reseller/konsumsi, ongkir, order
                                                &amp; invoice, eskalasi, program
                                                karyawan
                                            </span>
                                        </div>
                                        <ButtonLink
                                            href={prompt.url()}
                                            ukuran="sm"
                                            className="box-content h-[34px] px-3"
                                        >
                                            Riwayat versi
                                        </ButtonLink>
                                        <ButtonLink
                                            href={prompt.url()}
                                            ukuran="sm"
                                            varian="primary"
                                            className="box-content h-[34px] px-3"
                                        >
                                            Buka editor prompt
                                        </ButtonLink>
                                    </div>
                                </KartuPengaturan>
                            </>
                        )}

                        {(tabAktif === 'semua' ||
                            tabAktif === 'notifikasi') && (
                            <>
                                <KartuPengaturan
                                    judul="Notifikasi WhatsApp"
                                    sub={`Semua dikirim ke nomor supervisor (ADMIN_WA), kecuali order karyawan ke ${pj} (SARAH_WA). Klik untuk mematikan.`}
                                    rapat
                                >
                                    <div className="grid grid-cols-2 gap-x-4 gap-y-[9px] text-body max-md:grid-cols-1">
                                        {notifikasi(pj).map(
                                            ([kunci, label]) => {
                                                const nyala =
                                                    !kabarWaMati.includes(
                                                        kunci,
                                                    );

                                                return (
                                                    <button
                                                        key={kunci}
                                                        type="button"
                                                        aria-pressed={nyala}
                                                        title={
                                                            nyala
                                                                ? 'Matikan kabar WhatsApp ini (notifikasinya tetap masuk panel)'
                                                                : 'Nyalakan kabar WhatsApp ini'
                                                        }
                                                        onClick={() =>
                                                            router.patch(
                                                                kabarWa.url(),
                                                                {
                                                                    baris: kunci,
                                                                    nyala: !nyala,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex cursor-pointer items-center gap-2 text-left hover:text-ink-3 motion-safe:transition-colors',
                                                            !nyala &&
                                                                'text-muted line-through',
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                'size-1.5 shrink-0 rounded-full',
                                                                nyala
                                                                    ? 'bg-green-dot'
                                                                    : 'bg-line-strong',
                                                            )}
                                                        />
                                                        {label}
                                                    </button>
                                                );
                                            },
                                        )}
                                    </div>
                                    <div className="flex flex-col gap-[9px] border-t border-line-softer pt-3">
                                        {jadwal.map(([label, waktu]) => (
                                            <div
                                                key={label}
                                                className="flex items-center gap-2.5"
                                            >
                                                <span className="grow text-body font-semibold">
                                                    {label}
                                                </span>
                                                <span className="text-body text-ink-3">
                                                    {waktu}
                                                </span>
                                            </div>
                                        ))}
                                        <span className="text-caption text-muted">
                                            Jadwal diatur di workflow.{' '}
                                            <Link
                                                href="/otomasi"
                                                className="font-medium no-underline"
                                            >
                                                Lihat semua jadwal
                                            </Link>
                                        </span>
                                    </div>
                                </KartuPengaturan>

                                <KartuPengaturan
                                    judul="Arsip data"
                                    sub="Tiap Minggu 01.00 lewat workflow Arsip Data. Rekap jumlahnya dikirim ke supervisor."
                                    rapat
                                >
                                    <div className="grid grid-cols-2 gap-3 max-md:grid-cols-1">
                                        <IsianKunci
                                            label="Arsipkan order setelah"
                                            kunci="ARSIP_ORDER_HARI"
                                            error={salah('ARSIP_ORDER_HARI')}
                                        >
                                            <IsianAngka
                                                satuan="hari"
                                                {...teks('ARSIP_ORDER_HARI')}
                                            />
                                        </IsianKunci>
                                        <IsianKunci
                                            label="Arsipkan customer diam"
                                            kunci="ARSIP_CUSTOMER_HARI"
                                            error={salah('ARSIP_CUSTOMER_HARI')}
                                        >
                                            <IsianAngka
                                                satuan="hari"
                                                ukuran="h-10 w-[84px] rounded-[9px]"
                                                {...teks('ARSIP_CUSTOMER_HARI')}
                                            />
                                        </IsianKunci>
                                    </div>
                                    <span className="text-caption leading-[1.45] text-muted">
                                        Hanya order selesai, ditolak, atau
                                        dibatalkan. Baris disalin utuh ke tabel
                                        ARSIP_DATA dulu, baru dihapus dari tabel
                                        aslinya. Customer yang masih punya order
                                        berjalan tidak ikut diarsipkan.
                                    </span>
                                </KartuPengaturan>
                            </>
                        )}
                    </div>
                )}
            </IsiHalaman>
        </AppLayout>
    );
}
