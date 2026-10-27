import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { AppLayout, IsiHalaman } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { Checkbox, Input, Select, Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { StatStrip } from '@/components/ui/stat-strip';
import { cn } from '@/lib/utils';
import { percakapan } from '@/routes';
import { lanjut, simpan } from '@/routes/jeda';

type Aktif = {
    id: number;
    nama: string;
    nomor: string | null;
    /** short label of the bot number the chat came in on ("CS 2") */
    sesi: string | null;
    sejak: string;
    sampai: string;
    sisa: string;
    oleh: string | null;
    alasan: string | null;
    kabar: string;
};

type Riwayat = {
    id: number;
    nama: string;
    waktu: string;
    durasi: string;
    alasan: string | null;
    catatan: string | null;
    oleh: string | null;
};

type Props = {
    ringkasan: { label: string; nilai: string; meta: string }[];
    aktif: Aktif[];
    riwayat: Riwayat[];
    terakhir: { nama: string; jam: string } | null;
};

type SatuanDurasi = 'menit' | 'jam' | 'detik' | 'hari';

// narrow screens: the columns keep their width and the card scrolls sideways
const KOLOM_AKTIF =
    'grid grid-cols-[minmax(110px,1.2fr)_minmax(125px,1.2fr)_56px_minmax(100px,1.4fr)_auto] gap-2.5';
const KOLOM_RIWAYAT =
    'grid grid-cols-[minmax(90px,1fr)_minmax(110px,1.1fr)_60px_minmax(110px,1.5fr)_56px] gap-2';

const kelasIsian = 'h-10 rounded-[9px] px-3 text-body';

export default function Jeda({ ringkasan, aktif, riwayat, terakhir }: Props) {
    const form = useForm({
        customer: '',
        jumlah: 30,
        satuan: 'menit' as SatuanDurasi,
        tanpa_batas: false,
        durasi: '30 menit',
        alasan: '',
    });
    const [targetLanjut, setTargetLanjut] = useState<Aktif | null>(null);
    const [catatan, setCatatan] = useState('');
    const [sedangKirim, setSedangKirim] = useState(false);
    const textareaRef = useRef<HTMLTextAreaElement>(null);

    const ubahJumlah = (val: number) => {
        const j = Math.max(0, val);
        form.setData((prev) => ({
            ...prev,
            jumlah: j,
            durasi: prev.tanpa_batas ? 'Tanpa batas' : `${j} ${prev.satuan}`,
        }));
    };

    const ubahSatuan = (s: SatuanDurasi) => {
        form.setData((prev) => ({
            ...prev,
            satuan: s,
            durasi: prev.tanpa_batas ? 'Tanpa batas' : `${prev.jumlah} ${s}`,
        }));
    };

    const toggleTanpaBatas = (checked: boolean) => {
        form.setData((prev) => ({
            ...prev,
            tanpa_batas: checked,
            durasi: checked ? 'Tanpa batas' : `${prev.jumlah} ${prev.satuan}`,
        }));
    };

    useEffect(() => {
        if (targetLanjut) {
            const timer = setTimeout(() => textareaRef.current?.focus(), 50);
            return () => clearTimeout(timer);
        }
    }, [targetLanjut]);

    // pauses set or ended on WhatsApp, and expiring ones, show up by themselves
    usePoll(10000, {
        only: ['ringkasan', 'aktif', 'riwayat', 'terakhir', 'panel'],
    });

    const jeda = () => {
        if (!form.data.tanpa_batas && form.data.jumlah <= 0) {
            form.setError(
                'durasi',
                'Isi jumlah durasi minimal 1 atau centang Tanpa batas.',
            );
            return;
        }

        form.post(simpan.url(), {
            preserveScroll: true,
            onSuccess: () => form.reset('customer', 'alasan'),
        });
    };

    const bukaModalLanjut = (item: Aktif) => {
        setTargetLanjut(item);
        setCatatan('');
    };

    const kirimLanjut = () => {
        if (!targetLanjut || sedangKirim) {
            return;
        }

        setSedangKirim(true);
        router.post(
            lanjut.url(targetLanjut.id),
            { catatan },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTargetLanjut(null);
                    setCatatan('');
                },
                onFinish: () => setSedangKirim(false),
            },
        );
    };

    return (
        <AppLayout active="jeda">
            <Head title="Ambil alih chat" />
            <PageHeader
                rapat
                judul="Ambil alih chat"
                sub="Bot diam untuk customer tertentu selama admin membalas sendiri — sama dengan perintah JEDA dan LANJUT di WhatsApp"
            />
            <IsiHalaman className="gap-[18px] pt-[22px] pb-6">
                <StatStrip rapat stat={ringkasan} />

                <section className="grid min-h-0 grow grid-cols-[minmax(0,2fr)_minmax(0,1fr)] gap-5 max-xl:grid-cols-1">
                    <div className="flex min-w-0 flex-col gap-[18px]">
                        <div className="overflow-hidden rounded-card border border-line bg-surface max-xl:overflow-x-auto">
                            <KepalaKartu
                                judul="Sedang dijeda"
                                keterangan="Bot diam untuk customer ini; customer dikabari sekali"
                            />
                            <div
                                className={cn(
                                    KOLOM_AKTIF,
                                    'border-b border-line-softer bg-subtle px-[18px] py-2.5 text-caption font-medium text-muted',
                                )}
                            >
                                <span>Customer</span>
                                <span>Sejak → sampai</span>
                                <span>Oleh</span>
                                <span>Alasan</span>
                                <span />
                            </div>
                            {aktif.map((r) => (
                                <div
                                    key={r.id}
                                    className={cn(
                                        KOLOM_AKTIF,
                                        'items-center border-b border-line-softer px-[18px] py-3',
                                    )}
                                >
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className="truncate text-body font-semibold">
                                            {r.nama}
                                        </span>
                                        <span className="flex min-w-0 items-center gap-1.5">
                                            <span className="truncate font-mono text-tag text-muted">
                                                {r.nomor ?? '—'}
                                            </span>
                                            {r.sesi && (
                                                <span className="shrink-0 rounded-tag bg-line-softer px-1.5 text-tag font-medium whitespace-nowrap text-gray-text">
                                                    {r.sesi}
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                    <span className="flex min-w-0 flex-col items-start gap-1">
                                        <span className="text-caption font-semibold whitespace-nowrap">
                                            {r.sejak} → {r.sampai}
                                        </span>
                                        {/* a chip, so the ones about to expire stand out when scanning */}
                                        <span
                                            className={cn(
                                                'max-w-full truncate rounded-tag px-1.5 py-px text-tag font-medium',
                                                r.sampai === '∞'
                                                    ? 'bg-line-softer text-gray-text'
                                                    : 'bg-note-amber-bg text-amber-text',
                                            )}
                                        >
                                            {r.sisa}
                                        </span>
                                    </span>
                                    <span className="text-caption">
                                        {r.oleh}
                                    </span>
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className="text-caption">
                                            {r.alasan}
                                        </span>
                                        <span className="text-tag text-muted">
                                            {r.kabar}
                                        </span>
                                    </span>
                                    <span className="flex justify-end gap-1.5">
                                        <Link
                                            href={percakapan.url()}
                                            aria-label="Buka chat"
                                            title="Buka chat"
                                            className="box-content flex size-[34px] shrink-0 items-center justify-center rounded-control border border-line-strong bg-surface text-ink"
                                        >
                                            <Ikon nama="whatsapp" tebal={2} />
                                        </Link>
                                        <button
                                            type="button"
                                            onClick={() => bukaModalLanjut(r)}
                                            className="h-[34px] cursor-pointer rounded-control border border-line-strong bg-surface px-3 text-caption font-semibold whitespace-nowrap text-ink hover:bg-subtle motion-safe:transition-colors"
                                        >
                                            Lanjutkan bot
                                        </button>
                                    </span>
                                </div>
                            ))}
                            {aktif.length === 0 && (
                                <div className="p-7 text-center text-body text-muted">
                                    Tidak ada customer yang dijeda. Adiva
                                    menjawab semua chat.
                                </div>
                            )}
                        </div>

                        <div className="flex min-h-0 grow flex-col overflow-hidden rounded-card border border-line bg-surface max-xl:overflow-x-auto">
                            <KepalaKartu
                                judul="Riwayat jeda"
                                keterangan="Durasi dihitung otomatis saat LANJUT"
                            />
                            <div
                                className={cn(
                                    KOLOM_RIWAYAT,
                                    'border-b border-line-softer bg-subtle px-[18px] py-2.5 text-caption font-medium text-muted',
                                )}
                            >
                                <span>Customer</span>
                                <span>Mulai → selesai</span>
                                <span>Durasi</span>
                                <span>Alasan · catatan serah terima</span>
                                <span>Oleh</span>
                            </div>
                            <div className="overflow-auto max-xl:overflow-visible">
                                {riwayat.map((h) => (
                                    <div
                                        key={h.id}
                                        className={cn(
                                            KOLOM_RIWAYAT,
                                            'items-start border-b border-line-softer px-[18px] py-[11px] text-caption',
                                        )}
                                    >
                                        <span className="font-semibold">
                                            {h.nama}
                                        </span>
                                        <span className="text-ink-2">
                                            {h.waktu}
                                        </span>
                                        <span className="font-semibold">
                                            {h.durasi}
                                        </span>
                                        <span className="flex flex-col gap-0.5">
                                            <span>{h.alasan}</span>
                                            <span className="text-ink-3">
                                                {h.catatan}
                                            </span>
                                        </span>
                                        <span>{h.oleh}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="flex min-w-0 flex-col gap-[18px]">
                        <Kartu className="gap-3">
                            <h2 className="m-0 text-panel font-semibold">
                                Jeda customer
                            </h2>
                            <Isian
                                label="Customer"
                                galat={form.errors.customer}
                            >
                                <Input
                                    type="text"
                                    placeholder="Cari nama atau nomor WA"
                                    className={kelasIsian}
                                    value={form.data.customer}
                                    onChange={(e) =>
                                        form.setData('customer', e.target.value)
                                    }
                                />
                            </Isian>
                            {terakhir && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('customer', terakhir.nama)
                                    }
                                    className="h-[34px] shrink-0 cursor-pointer rounded-control border border-line bg-surface px-[11px] text-left text-caption font-medium whitespace-nowrap text-ink-2"
                                >
                                    Pakai chat terakhir:{' '}
                                    <strong className="font-semibold text-ink">
                                        {terakhir.nama}
                                    </strong>{' '}
                                    · {terakhir.jam}
                                </button>
                            )}
                            <div className="flex flex-col gap-2">
                                <div className="flex items-center justify-between">
                                    <span className="text-caption font-semibold text-ink">
                                        Durasi
                                    </span>
                                    <span
                                        className={cn(
                                            'text-tag font-medium',
                                            form.data.tanpa_batas
                                                ? 'text-muted'
                                                : form.data.jumlah <= 0
                                                  ? 'text-red-text'
                                                  : 'text-primary',
                                        )}
                                    >
                                        {form.data.tanpa_batas
                                            ? 'Tanpa batas (sampai aktif lagi)'
                                            : form.data.jumlah <= 0
                                              ? 'Isi angka minimal 1'
                                              : `Total: ${form.data.jumlah} ${form.data.satuan}`}
                                    </span>
                                </div>

                                <div className="grid grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)] gap-2.5">
                                    <label className="flex flex-col gap-1">
                                        <span className="text-tag text-muted">
                                            Jumlah durasi
                                        </span>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={9999}
                                            disabled={form.data.tanpa_batas}
                                            className={cn(
                                                kelasIsian,
                                                form.data.tanpa_batas &&
                                                    'cursor-not-allowed border-line bg-subtle text-muted',
                                            )}
                                            placeholder="Mis. 30"
                                            value={form.data.jumlah || ''}
                                            onChange={(e) =>
                                                ubahJumlah(
                                                    parseInt(
                                                        e.target.value,
                                                        10,
                                                    ) || 0,
                                                )
                                            }
                                        />
                                    </label>

                                    <label className="flex flex-col gap-1">
                                        <span className="text-tag text-muted">
                                            Satuan
                                        </span>
                                        <Select
                                            disabled={form.data.tanpa_batas}
                                            className={cn(
                                                kelasIsian,
                                                form.data.tanpa_batas &&
                                                    'cursor-not-allowed border-line bg-subtle text-muted',
                                            )}
                                            value={form.data.satuan}
                                            onChange={(e) =>
                                                ubahSatuan(
                                                    e.target
                                                        .value as SatuanDurasi,
                                                )
                                            }
                                        >
                                            <option value="menit">Menit</option>
                                            <option value="jam">Jam</option>
                                            <option value="detik">Detik</option>
                                            <option value="hari">Hari</option>
                                        </Select>
                                    </label>
                                </div>

                                <div className="pt-1">
                                    <Checkbox
                                        label="Tanpa batas waktu"
                                        sub="Diingatkan ke supervisor tiap pagi 09.00. Selama dijeda, customer tidak ikut sapaan ulang."
                                        checked={form.data.tanpa_batas}
                                        onChange={(e) =>
                                            toggleTanpaBatas(e.target.checked)
                                        }
                                    />
                                </div>

                                {form.errors.durasi && (
                                    <span className="text-caption text-red-text">
                                        {form.errors.durasi}
                                    </span>
                                )}
                            </div>
                            <Isian label="Alasan" galat={form.errors.alasan}>
                                <Input
                                    type="text"
                                    placeholder="Mis. komplain, nego, order via telepon"
                                    className={kelasIsian}
                                    value={form.data.alasan}
                                    onChange={(e) =>
                                        form.setData('alasan', e.target.value)
                                    }
                                />
                            </Isian>
                            <Button
                                varian="primary"
                                className="h-10 text-body"
                                aria-disabled={form.processing}
                                onClick={jeda}
                            >
                                Jeda bot sekarang
                            </Button>
                            <div className="text-tag leading-[1.45] text-muted">
                                Jika waktu jeda habis, Adiva otomatis menjawab
                                lagi dan supervisor dikabari.
                            </div>
                        </Kartu>
                    </div>
                </section>
            </IsiHalaman>

            {targetLanjut && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-ink/30 p-4 motion-safe:animate-[pudar_120ms_ease-out]"
                    onClick={() => !sedangKirim && setTargetLanjut(null)}
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="judul-modal-lanjut"
                        onClick={(e) => e.stopPropagation()}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape' && !sedangKirim) {
                                setTargetLanjut(null);
                            }
                        }}
                        className="w-full max-w-[480px] rounded-card border border-line bg-surface p-5 font-sans text-ink shadow-lg motion-safe:animate-[muncul_150ms_ease-out]"
                    >
                        <div className="flex items-center justify-between gap-3 border-b border-line-softer pb-3.5">
                            <div className="flex items-center gap-2.5">
                                <div className="flex size-8 items-center justify-center rounded-control bg-subtle text-primary">
                                    <Ikon nama="whatsapp" tebal={2} />
                                </div>
                                <h2
                                    id="judul-modal-lanjut"
                                    className="m-0 text-panel font-semibold"
                                >
                                    Serah terima ke Adiva
                                </h2>
                            </div>
                            <button
                                type="button"
                                aria-label="Tutup"
                                disabled={sedangKirim}
                                onClick={() => setTargetLanjut(null)}
                                className="flex size-8 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-muted hover:bg-line-softer hover:text-ink disabled:opacity-50"
                            >
                                <Ikon nama="tutup" />
                            </button>
                        </div>

                        <div className="mt-3.5 rounded-[9px] border border-line-softer bg-subtle p-3 text-caption">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-body font-semibold text-ink">
                                    {targetLanjut.nama}
                                </span>
                                {targetLanjut.sesi && (
                                    <span className="shrink-0 rounded-tag bg-line-softer px-1.5 py-0.5 text-tag font-medium text-gray-text">
                                        {targetLanjut.sesi}
                                    </span>
                                )}
                            </div>
                            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-muted">
                                {targetLanjut.nomor && (
                                    <span className="font-mono text-tag text-ink-2">
                                        {targetLanjut.nomor}
                                    </span>
                                )}
                                {targetLanjut.alasan && (
                                    <span>
                                        Alasan dijeda:{' '}
                                        <strong className="font-medium text-ink-2">
                                            {targetLanjut.alasan}
                                        </strong>
                                    </span>
                                )}
                            </div>
                        </div>

                        <div className="mt-3.5 flex flex-col gap-2">
                            <div className="flex items-center justify-between">
                                <label
                                    htmlFor="catatan-adiva"
                                    className="text-caption font-semibold text-ink"
                                >
                                    Catatan untuk Adiva{' '}
                                    <span className="font-normal text-muted">
                                        (opsional)
                                    </span>
                                </label>
                                {catatan.length > 0 && (
                                    <span className="text-tag text-muted">
                                        {catatan.length}/500
                                    </span>
                                )}
                            </div>
                            <Textarea
                                id="catatan-adiva"
                                ref={textareaRef}
                                rows={3}
                                maxLength={500}
                                placeholder="Mis. kompensasi 2 bungkus di order berikutnya sudah disepakati"
                                className="resize-none rounded-[9px] px-3 py-2 text-body leading-[1.4]"
                                value={catatan}
                                onChange={(e) => setCatatan(e.target.value)}
                                onKeyDown={(e) => {
                                    if (
                                        (e.metaKey || e.ctrlKey) &&
                                        e.key === 'Enter'
                                    ) {
                                        e.preventDefault();
                                        kirimLanjut();
                                    }
                                }}
                            />
                            <p className="m-0 text-tag leading-[1.45] text-muted">
                                Catatan disimpan di profil customer dan dibaca
                                Adiva saat melanjutkan percakapan.
                            </p>
                        </div>

                        <div className="mt-5 flex items-center justify-end gap-2 border-t border-line-softer pt-3.5">
                            <Button
                                varian="secondary"
                                disabled={sedangKirim}
                                onClick={() => setTargetLanjut(null)}
                            >
                                Batal
                            </Button>
                            <Button
                                varian="primary"
                                disabled={sedangKirim}
                                data-memuat={sedangKirim ? '' : undefined}
                                onClick={kirimLanjut}
                            >
                                {sedangKirim
                                    ? 'Melanjutkan...'
                                    : 'Lanjutkan bot'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function KepalaKartu({
    judul,
    keterangan,
}: {
    judul: string;
    keterangan: string;
}) {
    return (
        <div className="flex items-center border-b border-line-softer px-[18px] py-3.5">
            <h2 className="m-0 grow text-panel font-semibold">{judul}</h2>
            <span className="text-caption text-muted">{keterangan}</span>
        </div>
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

function Isian({
    label,
    galat,
    children,
}: {
    label: string;
    galat?: string;
    children: ReactNode;
}) {
    return (
        <label className="flex flex-col gap-1.5">
            <span className="text-caption font-semibold">{label}</span>
            {children}
            {galat && (
                <span className="text-caption text-red-text">{galat}</span>
            )}
        </label>
    );
}
