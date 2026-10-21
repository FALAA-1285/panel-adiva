import {
    Head,
    Link,
    router,
    useForm,
    usePage,
    usePoll,
} from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { MenuGambar, MenuTemplate } from '@/components/percakapan/pemilih';
import type {
    FotoProduk,
    TemplateBalasan,
} from '@/components/percakapan/pemilih';
import { AppLayout } from '@/components/ui/app-layout';
import { Button, ButtonLink } from '@/components/ui/button';
import { Textarea } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import type { NamaIkon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { Avatar } from '@/components/ui/teks';
import { sedangMengetik } from '@/components/ui/topbar';
import { cocok, LAYAR } from '@/hooks/use-layar';
import { cn } from '@/lib/utils';
import { jeda, pelanggan, percakapan as rutePercakapan } from '@/routes';
import { lanjut } from '@/routes/jeda';
import { catatan as ubahCatatan } from '@/routes/pelanggan';
import { balas, jawab, kirimUlang, resetMemori } from '@/routes/percakapan';
import { detail as detailPesanan } from '@/routes/pesanan';

type Filter = { cari: string; tab: string; tipe: string | null };

type Baris = {
    id: number;
    inisial: string;
    nama: string;
    waktu: string;
    pratinjau: string | null;
    skor: string;
    tahap: string;
    /** customer lines at the end of the chat that nobody has answered yet */
    menunggu: number;
    tanda: { teks: string; nada: Nada };
};

type Pesan = {
    id: number;
    jenis: 'customer' | 'adiva' | 'admin' | 'sistem';
    oleh: string | null;
    teks: string | null;
    jam: string;
    foto: string | null;
    /** a customer's photo or file, opened from WAHA */
    lampiran: string | null;
    tombol: string[];
    /** Lines the panel sends: still on its way, or WhatsApp did not take it. */
    kirim: 'MENUNGGU' | 'GAGAL' | null;
};

type Percakapan = {
    id: number;
    inisial: string;
    nama: string;
    /** What {nama} becomes in a reply template. */
    panggilan: string;
    nomor: string;
    status: { label: string; nada: Nada };
    jeda: number | null;
    pesan: Pesan[];
    petunjuk: string;
    ringkasan: string | null;
    alasanAdmin: string | null;
    /** a question Adiva passed on and is still waiting for an answer */
    eskalasi: { pertanyaan: string; sejak: string } | null;
    isian: [string, string][];
    catatan: string | null;
    pesanan: {
        id: string;
        total: string;
        status: { label: string; nada: Nada };
    }[];
};

type Props = {
    filter: Filter;
    waha: { teks: string; tersambung: boolean };
    jumlah: { status: Record<string, number>; tipe: Record<string, number> };
    muat: { batas: number; tampil: number; total: number };
    daftar: Baris[];
    percakapan: Percakapan | null;
    template: TemplateBalasan[];
    gambar: FotoProduk[];
};

/** The filter rail beside the list: two groups that narrow each other. */
const STATUS = [
    ['semua', 'Semua', 'percakapan'],
    ['belum-dibalas', 'Belum dibalas', 'riwayat'],
    ['butuh-admin', 'Butuh admin', 'pengguna'],
    ['dijeda', 'Dijeda', 'jeda'],
] as const;

const TIPE = [
    ['RESELLER', 'Reseller'],
    ['RETAIL', 'Retail'],
    ['KARYAWAN', 'Karyawan'],
    ['BELUM_JELAS', 'Belum jelas'],
] as const;

const warnaSkor: Record<string, string> = {
    HOT: 'text-red-text',
    WARM: 'text-amber-text',
    COLD: 'text-muted',
};

/** Inbox.dc.html flag and status colours (#1D4ED8, #B45309, #B91C1C, #4B5563). */
const warnaNada: Partial<Record<Nada, string>> = {
    blue: 'text-primary-ink',
    amber: 'text-amber-text',
    red: 'text-red-text',
    gray: 'text-gray-text',
    green: 'text-green-text',
    violet: 'text-violet-text',
};

const query = (f: Filter, pilih?: number) => ({
    cari: f.cari || undefined,
    tab: f.tab === 'semua' ? undefined : f.tab,
    tipe: f.tipe ?? undefined,
    pilih,
});

/**
 * One line in the filter rail: label on the left, how many it would give on the right.
 * Picking the type that is already on clears it, so the rail never traps the list.
 */
function BarisSaring({
    label,
    ikon,
    n,
    aktif,
    onPilih,
}: {
    label: string;
    ikon?: NamaIkon;
    n: number;
    aktif: boolean;
    onPilih: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={aktif}
            onClick={onPilih}
            className={cn(
                'flex w-full cursor-pointer items-center gap-2.5 rounded-nav border-0 px-2.5 py-1.5 text-left text-body',
                aktif
                    ? 'bg-nav-active font-semibold text-primary-ink'
                    : 'bg-transparent text-ink-2 hover:bg-subtle',
                'motion-safe:transition-colors',
            )}
        >
            {ikon && <Ikon nama={ikon} ukuran={15} />}
            <span className="grow truncate">{label}</span>
            {n > 0 && (
                <span
                    className={cn(
                        'shrink-0 text-tag font-semibold tabular-nums',
                        aktif ? 'text-primary-ink' : 'text-muted',
                    )}
                >
                    {n.toLocaleString('id-ID')}
                </span>
            )}
        </button>
    );
}

export default function HalamanPercakapan({
    filter,
    waha,
    jumlah,
    muat,
    daftar,
    percakapan,
    template,
    gambar,
}: Props) {
    const [isian, setIsian] = useState(filter);
    const [dipilih, setDipilih] = useState(percakapan?.id);
    const [info, setInfo] = useState(false);
    const pertama = useRef(true);
    // below 1024px one panel shows: the list, or the chat once one is picked (?pilih=)
    const lihatUtas = new URLSearchParams(
        usePage().url.split('?')[1] ?? '',
    ).has('pilih');

    // new chats and messages from n8n appear by themselves (not in the design)
    usePoll(5000, {
        only: ['daftar', 'jumlah', 'percakapan', 'waha', 'panel'],
    });

    // search and tabs reload the list, a moment after typing stops
    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(
            () =>
                router.get(
                    rutePercakapan.url({ query: query(isian) }),
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
        setInfo(false);
        router.get(
            rutePercakapan.url({ query: query(isian, id) }),
            {},
            {
                only: ['percakapan'],
                preserveState: true,
                preserveScroll: true,
                // small screens: opening a chat is a step the browser's Back undoes
                replace: cocok(LAYAR.sedang),
            },
        );
    };

    const keDaftar = () =>
        router.get(
            rutePercakapan.url({ query: query(isian) }),
            {},
            { only: ['percakapan'], preserveState: true, preserveScroll: true },
        );

    // J / K: next or previous chat; R: the reply box
    useEffect(() => {
        const tekan = (e: KeyboardEvent) => {
            if (
                sedangMengetik(e.target) ||
                e.metaKey ||
                e.ctrlKey ||
                e.altKey
            ) {
                return;
            }

            const key = e.key.toLowerCase();

            if (key === 'j' || key === 'k') {
                const i = daftar.findIndex((b) => b.id === dipilih);
                const ke =
                    daftar[
                        Math.min(
                            daftar.length - 1,
                            Math.max(0, i + (key === 'j' ? 1 : -1)),
                        )
                    ];

                if (ke && ke.id !== dipilih) {
                    e.preventDefault();
                    pilih(ke.id);
                }
            }

            if (key === 'r') {
                e.preventDefault();
                document.getElementById('balasan-admin')?.focus();
            }
        };

        document.addEventListener('keydown', tekan);

        return () => document.removeEventListener('keydown', tekan);
    });

    return (
        <AppLayout active="inbox" penuhLayar>
            <Head title="Percakapan" />
            <PageHeader
                rapat
                judul="Percakapan"
                sub="Pantau jawaban Adiva secara langsung, ambil alih kapan saja"
                aksi={
                    <span
                        className={cn(
                            'flex items-center gap-1.5 pb-1 text-caption font-medium whitespace-nowrap',
                            waha.tersambung
                                ? 'text-green-text'
                                : 'text-red-text',
                        )}
                    >
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                waha.tersambung ? 'bg-green-dot' : 'bg-red-dot',
                            )}
                        />
                        {waha.teks}
                    </span>
                }
            />
            <div className="mx-8 mt-5 mb-6 flex min-h-0 grow overflow-hidden rounded-card border border-line max-md:mx-0 max-md:mt-3 max-md:mb-0 max-md:rounded-none max-md:border-x-0 max-md:border-b-0">
                <nav
                    aria-label="Saring percakapan"
                    className={cn(
                        'flex w-[188px] shrink-0 flex-col gap-4 overflow-y-auto border-r border-line bg-subtle px-2.5 py-3.5',
                        // narrower screens get the same filters as the chip row above the list
                        'max-xl:hidden',
                    )}
                >
                    <div className="flex flex-col gap-0.5">
                        <span className="px-2.5 pb-1 text-tag font-semibold tracking-[0.06em] text-muted uppercase">
                            Status
                        </span>
                        {STATUS.map(([nilai, label, ikon]) => (
                            <BarisSaring
                                key={nilai}
                                label={label}
                                ikon={ikon}
                                n={jumlah.status[nilai] ?? 0}
                                aktif={isian.tab === nilai}
                                onPilih={() =>
                                    setIsian({ ...isian, tab: nilai })
                                }
                            />
                        ))}
                    </div>
                    <div className="flex flex-col gap-0.5">
                        <span className="px-2.5 pb-1 text-tag font-semibold tracking-[0.06em] text-muted uppercase">
                            Tipe customer
                        </span>
                        {TIPE.map(([nilai, label]) => (
                            <BarisSaring
                                key={nilai}
                                label={label}
                                n={jumlah.tipe[nilai] ?? 0}
                                aktif={isian.tipe === nilai}
                                onPilih={() =>
                                    setIsian({
                                        ...isian,
                                        tipe:
                                            isian.tipe === nilai ? null : nilai,
                                    })
                                }
                            />
                        ))}
                    </div>
                </nav>

                <section
                    aria-label="Daftar chat"
                    className={cn(
                        'box-content flex w-[290px] shrink-0 flex-col border-r border-line bg-surface',
                        'max-lg:w-full max-lg:border-r-0',
                        lihatUtas && 'max-lg:hidden',
                    )}
                >
                    <div className="flex flex-col gap-2.5 border-b border-line-softer px-4 pt-3.5 pb-2.5">
                        <label className="box-border flex h-10 items-center gap-2 rounded-card border border-line px-3 text-muted">
                            <Ikon nama="cari" tebal={2} />
                            <input
                                type="search"
                                aria-label="Cari chat"
                                placeholder="Cari nama atau nomor"
                                className="grow border-none bg-transparent text-body text-ink outline-none"
                                value={isian.cari}
                                onChange={(e) =>
                                    setIsian({ ...isian, cari: e.target.value })
                                }
                            />
                        </label>
                        {/* the rail's filters, for screens too narrow to hold it */}
                        <div className="flex gap-1.5 [scrollbar-width:none] overflow-x-auto xl:hidden">
                            {[
                                ...STATUS.map(
                                    ([nilai, label]) =>
                                        [
                                            `tab:${nilai}`,
                                            label,
                                            jumlah.status[nilai] ?? 0,
                                            isian.tab === nilai,
                                            () =>
                                                setIsian({
                                                    ...isian,
                                                    tab: nilai,
                                                }),
                                        ] as const,
                                ),
                                ...TIPE.map(
                                    ([nilai, label]) =>
                                        [
                                            `tipe:${nilai}`,
                                            label,
                                            jumlah.tipe[nilai] ?? 0,
                                            isian.tipe === nilai,
                                            () =>
                                                setIsian({
                                                    ...isian,
                                                    tipe:
                                                        isian.tipe === nilai
                                                            ? null
                                                            : nilai,
                                                }),
                                        ] as const,
                                ),
                            ].map(([kunci, label, n, aktif, onPilih]) => (
                                <button
                                    key={kunci}
                                    type="button"
                                    aria-pressed={aktif}
                                    onClick={onPilih}
                                    className={cn(
                                        'h-[30px] shrink-0 cursor-pointer rounded-full border px-2.5 text-caption font-semibold whitespace-nowrap',
                                        aktif
                                            ? 'border-primary-line bg-primary-soft text-primary-ink'
                                            : 'border-line-strong bg-surface text-ink hover:bg-subtle',
                                        'motion-safe:transition-colors',
                                    )}
                                >
                                    {label} {n}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="flex flex-col gap-1 border-b border-line-softer px-4 py-2.5">
                        <span className="text-caption font-medium text-ink-2 tabular-nums">
                            {muat.total === 0
                                ? 'Tidak ada percakapan'
                                : muat.tampil < muat.total
                                  ? `${muat.tampil} terbaru dari ${muat.total.toLocaleString('id-ID')} percakapan`
                                  : `${muat.total.toLocaleString('id-ID')} percakapan`}
                        </span>
                        {muat.tampil < muat.total && (
                            <span className="text-caption text-muted">
                                Daftar hanya memuat yang terbaru. Pakai pencarian
                                atau saringan untuk menjangkau yang lebih lama.
                            </span>
                        )}
                    </div>
                    <div className="grow overflow-auto">
                        {daftar.map((b) => (
                            <BarisChat
                                key={b.id}
                                baris={b}
                                terpilih={b.id === dipilih}
                                onPilih={() => pilih(b.id)}
                            />
                        ))}
                    </div>
                </section>

                {percakapan && (
                    <Utas
                        key={percakapan.id}
                        percakapan={percakapan}
                        template={template}
                        gambar={gambar}
                        tampil={lihatUtas}
                        onKembali={keDaftar}
                        onInfo={() => setInfo(true)}
                    />
                )}
                {percakapan && (
                    <Penilaian
                        percakapan={percakapan}
                        buka={info}
                        onTutup={() => setInfo(false)}
                    />
                )}
                {!percakapan && <TidakAdaYangDipilih kosong={muat.total === 0} />}
            </div>
        </AppLayout>
    );
}

/** Nothing picked, or the filters left nothing: say which of the two it is. */
function TidakAdaYangDipilih({ kosong }: { kosong: boolean }) {
    return (
        <section
            aria-label="Isi percakapan"
            className="flex grow flex-col items-center justify-center gap-3 bg-surface px-8 text-center max-lg:hidden"
        >
            <span className="flex size-12 items-center justify-center rounded-card bg-primary-soft text-primary">
                <Ikon nama="percakapan" ukuran={22} />
            </span>
            <span className="text-judul font-semibold">
                {kosong ? 'Belum ada percakapan' : 'Belum ada yang dipilih'}
            </span>
            <span className="max-w-[360px] text-body text-muted">
                {kosong
                    ? 'Tidak ada chat yang cocok dengan saringan ini. Ubah saringan atau kosongkan pencarian.'
                    : 'Pilih satu chat di daftar untuk melihat isinya dan membalas.'}
            </span>
            <span className="flex items-center gap-1.5 text-caption text-muted">
                <Ikon nama="whatsapp" ukuran={14} />
                WhatsApp
            </span>
        </section>
    );
}

function BarisChat({
    baris: b,
    terpilih,
    onPilih,
}: {
    baris: Baris;
    terpilih: boolean;
    onPilih: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={terpilih}
            onClick={onPilih}
            className={cn(
                'flex w-full cursor-pointer items-start gap-3 border-0 border-b border-line-softer px-4 py-3 text-ink',
                terpilih
                    ? 'bg-selected shadow-[inset_2px_0_0_var(--color-primary)]'
                    : 'bg-surface hover:bg-subtle',
                'motion-safe:transition-colors motion-safe:duration-150',
            )}
        >
            <Avatar inisial={b.inisial} ukuran={36} ukuranTeks={13} />
            <span className="flex min-w-0 grow flex-col gap-[3px] text-left">
                <span className="flex items-center gap-1.5">
                    <span className="grow truncate text-panel font-semibold">
                        {b.nama}
                    </span>
                    <span className="text-tag text-muted">{b.waktu}</span>
                </span>
                <span className="flex items-center gap-2">
                    <span className="grow truncate text-caption text-ink-3">
                        {b.pratinjau}
                    </span>
                    {b.menunggu > 0 && (
                        <span
                            className="shrink-0 rounded-chip bg-primary px-1.5 py-px text-tag font-semibold text-white tabular-nums"
                            title={`${b.menunggu} pesan belum dibalas`}
                        >
                            {b.menunggu}
                        </span>
                    )}
                </span>
                <span className="flex flex-wrap items-center gap-1.5 pt-px">
                    <span
                        className={cn(
                            'text-tag font-semibold tracking-[0.02em] whitespace-nowrap',
                            warnaSkor[b.skor],
                        )}
                    >
                        {b.skor}
                    </span>
                    <span className="text-caption text-line-strong">·</span>
                    <span className="text-caption whitespace-nowrap text-muted">
                        {b.tahap}
                    </span>
                    <span className="text-caption text-line-strong">·</span>
                    <span
                        className={cn(
                            'text-caption font-medium whitespace-nowrap',
                            warnaNada[b.tanda.nada],
                        )}
                    >
                        {b.tanda.teks}
                    </span>
                </span>
            </span>
        </button>
    );
}

function Utas({
    percakapan: p,
    template,
    gambar,
    tampil,
    onKembali,
    onInfo,
}: {
    percakapan: Percakapan;
    template: TemplateBalasan[];
    gambar: FotoProduk[];
    /** small screens: the chat is open (else the list shows) */
    tampil: boolean;
    onKembali: () => void;
    onInfo: () => void;
}) {
    const [teks, setTeks] = useState('');
    const isi = useRef<HTMLDivElement>(null);
    const [adaBaru, setAdaBaru] = useState(false);
    const jumlahPesan = useRef(p.pesan.length);

    // a new message: follow it when the bottom was in view, else offer "↓ Pesan baru"
    useLayoutEffect(() => {
        const el = isi.current;

        if (!el || p.pesan.length <= jumlahPesan.current) {
            jumlahPesan.current = p.pesan.length;

            return;
        }

        jumlahPesan.current = p.pesan.length;
        const diBawah = el.scrollHeight - el.scrollTop - el.clientHeight < 160;

        if (diBawah) {
            el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
        } else {
            setAdaBaru(true);
        }
    }, [p.pesan.length]);

    const keBawah = () => {
        isi.current?.scrollTo({
            top: isi.current.scrollHeight,
            behavior: 'smooth',
        });
        setAdaBaru(false);
    };

    const [galat, setGalat] = useState<string>();
    // "Lampirkan gambar produk": sent with the reply, the text as its caption
    const [foto, setFoto] = useState<FotoProduk | null>(null);

    const kirim = () =>
        router.post(
            balas.url(p.id),
            { teks, gambar: foto?.id ?? null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTeks('');
                    setFoto(null);
                    setGalat(undefined);
                },
                onError: (e) => setGalat(e.teks ?? e.gambar),
            },
        );

    return (
        <section
            aria-label="Isi percakapan"
            className={cn(
                'relative flex min-w-0 grow flex-col bg-subtle',
                !tampil && 'max-lg:hidden',
            )}
        >
            <div className="box-border flex h-[68px] shrink-0 items-center gap-3 border-b border-line bg-surface px-5 max-md:gap-2 max-md:px-3">
                <button
                    type="button"
                    aria-label="Kembali ke daftar chat"
                    onClick={onKembali}
                    className="-ml-1 flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-ink-2 hover:bg-line-softer lg:hidden"
                >
                    <Ikon nama="kembali" ukuran={20} />
                </button>
                <Avatar inisial={p.inisial} ukuran={36} ukuranTeks={13} />
                <div className="flex min-w-0 grow flex-col gap-0.5">
                    <div className="truncate text-panel font-semibold">
                        {p.nama}
                    </div>
                    <div className="flex min-w-0 items-center gap-2">
                        <span className="truncate font-mono text-caption text-muted">
                            {p.nomor}
                        </span>
                        <span
                            className={cn(
                                'shrink-0 text-caption font-medium whitespace-nowrap',
                                warnaNada[p.status.nada],
                            )}
                        >
                            {p.status.label}
                        </span>
                    </div>
                </div>
                {p.jeda !== null ? (
                    <Button
                        varian="primary"
                        className="max-md:px-2.5"
                        onClick={() =>
                            p.jeda !== null &&
                            router.post(
                                lanjut.url(p.jeda),
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        <span className="max-md:hidden">Lanjutkan bot</span>
                        <span className="md:hidden">Lanjutkan</span>
                    </Button>
                ) : (
                    <ButtonLink
                        href={jeda.url()}
                        aria-label="Jeda bot"
                        className="box-content max-md:px-2.5"
                    >
                        <Ikon nama="jeda" ukuran={15} tebal={2} />
                        <span className="max-md:hidden">Jeda bot</span>
                    </ButtonLink>
                )}
                <button
                    type="button"
                    aria-label="Reset memori chat"
                    title="Reset memori chat — mulai sesi ingatan AI baru (mem_gen +1)"
                    onClick={() =>
                        router.post(
                            resetMemori.url(p.id),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    className="flex size-[38px] shrink-0 cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface text-ink hover:bg-subtle motion-safe:transition-colors"
                >
                    <Ikon nama="segarkan" tebal={2} />
                </button>
                <Link
                    href={pelanggan.url({ query: { pilih: p.id } })}
                    className="box-content flex h-[38px] shrink-0 items-center rounded-[9px] border border-line-strong bg-surface px-3 text-body font-semibold whitespace-nowrap text-ink no-underline hover:bg-subtle motion-safe:transition-colors max-md:hidden"
                >
                    Profil
                </Link>
                <button
                    type="button"
                    aria-label="Info customer"
                    aria-controls="info-customer"
                    onClick={onInfo}
                    className="flex size-[38px] shrink-0 cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface text-ink hover:bg-subtle 2xl:hidden"
                >
                    <Ikon nama="info" tebal={2} />
                </button>
            </div>

            <div
                ref={isi}
                onScroll={(e) => {
                    const el = e.currentTarget;

                    if (el.scrollHeight - el.scrollTop - el.clientHeight < 40) {
                        setAdaBaru(false);
                    }
                }}
                className="flex min-h-0 grow flex-col gap-2.5 overflow-auto px-6 py-[18px] max-md:px-3"
            >
                {p.pesan.map((m) => (
                    <Gelembung key={m.id} pesan={m} />
                ))}
            </div>
            {adaBaru && (
                <button
                    type="button"
                    onClick={keBawah}
                    className="absolute bottom-[150px] left-1/2 z-10 flex -translate-x-1/2 cursor-pointer items-center gap-1.5 rounded-full border border-primary-line bg-surface px-3.5 py-1.5 text-caption font-semibold text-primary-ink hover:bg-primary-soft motion-safe:animate-[muncul_160ms_ease-out]"
                >
                    <Ikon nama="bawah" ukuran={14} tebal={2} />
                    Pesan baru
                </button>
            )}

            <div className="flex shrink-0 flex-col gap-2 border-t border-line bg-surface px-5 pt-3 pb-4 max-md:px-3 max-md:pb-3">
                <div className="flex items-center gap-2 text-caption text-ink-3">
                    <Ikon nama="info" ukuran={14} tebal={2} />
                    <span className="grow">{galat ?? p.petunjuk}</span>
                </div>
                {foto && (
                    <span className="flex items-center gap-1.5 self-start rounded-control border border-line bg-subtle py-1 pr-1 pl-2.5 text-caption text-ink-2">
                        <Ikon nama="gambar" ukuran={14} />
                        Foto produk · {foto.judul}
                        <button
                            type="button"
                            aria-label="Batalkan foto"
                            onClick={() => setFoto(null)}
                            className="flex size-6 cursor-pointer items-center justify-center border-none bg-transparent text-muted"
                        >
                            <Ikon nama="tutup" ukuran={13} />
                        </button>
                    </span>
                )}
                <div className="flex items-end gap-2 max-md:flex-wrap">
                    <MenuGambar gambar={gambar} onPilih={setFoto} />
                    <MenuTemplate
                        template={template}
                        panggilan={p.panggilan}
                        teks={teks}
                        onPakai={(isi) =>
                            setTeks(
                                teks.trim() === '' ? isi : `${teks}\n${isi}`,
                            )
                        }
                    />
                    <Textarea
                        id="balasan-admin"
                        aria-label="Tulis balasan admin"
                        aria-keyshortcuts="R"
                        rows={2}
                        placeholder="Tulis balasan sebagai admin…"
                        className="grow resize-none rounded-card px-3 py-2.5 leading-[1.4] max-md:order-first max-md:basis-full"
                        value={teks}
                        onChange={(e) => setTeks(e.target.value)}
                        onKeyDown={(e) => {
                            // Ctrl/⌘ + Enter sends
                            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                e.preventDefault();
                                kirim();
                            }
                        }}
                    />
                    <Button
                        varian="primary"
                        className="h-[42px] px-4 max-md:ml-auto"
                        onClick={kirim}
                    >
                        <Ikon nama="kirim" tebal={2} />
                        Kirim
                    </Button>
                </div>
            </div>
        </section>
    );
}

function Gelembung({ pesan: m }: { pesan: Pesan }) {
    if (m.jenis === 'sistem') {
        return (
            <div className="max-w-[560px] self-center px-3 py-0.5 text-center text-caption whitespace-nowrap text-muted max-md:whitespace-normal">
                {m.teks}
            </div>
        );
    }

    if (m.jenis === 'customer') {
        return (
            <div className="flex max-w-[460px] flex-col gap-1 self-start max-md:max-w-[85%]">
                <div className="rounded-[12px_12px_12px_4px] border border-line bg-surface px-3 py-2.5 text-body leading-[1.5] whitespace-pre-line">
                    {m.teks}
                    {m.lampiran && (
                        <a
                            href={m.lampiran}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-1.5 flex items-center gap-1.5 text-caption font-semibold no-underline hover:underline"
                        >
                            <Ikon nama="gambar" ukuran={15} />
                            Buka lampiran
                        </a>
                    )}
                </div>
                <span className="text-tag text-muted">{m.jam}</span>
            </div>
        );
    }

    const adiva = m.jenis === 'adiva';

    return (
        <div className="flex max-w-[480px] flex-col items-end gap-1 self-end max-md:max-w-[85%]">
            <span
                className={cn(
                    'text-tag font-semibold',
                    adiva ? 'text-primary-ink' : 'text-violet-text',
                )}
            >
                {m.oleh}
            </span>
            <div
                className={cn(
                    'rounded-[12px_12px_4px_12px] border px-3 py-2.5 text-body leading-[1.5]',
                    adiva
                        ? 'border-[#DCE6FB] bg-[#EEF3FE]'
                        : 'border-[#E7E2FA] bg-[#F4F2FD]',
                )}
            >
                {m.foto && (
                    <div className="mb-2 flex h-[150px] w-[240px] flex-col items-center justify-center gap-1.5 rounded-control bg-line text-ink-3">
                        <Ikon nama="gambar" ukuran={22} />
                        <span className="px-[18px] text-center text-caption font-medium">
                            {m.foto}
                        </span>
                    </div>
                )}
                <div className="whitespace-pre-line">{m.teks}</div>
            </div>
            {m.tombol.length > 0 && (
                <div className="flex flex-wrap justify-end gap-1.5">
                    {m.tombol.map((t) => (
                        <span
                            key={t}
                            className="rounded-control border border-line-strong bg-surface px-[11px] py-[5px] text-caption font-medium whitespace-nowrap text-primary-ink"
                        >
                            {t}
                        </span>
                    ))}
                </div>
            )}
            {m.kirim === 'GAGAL' ? (
                <span className="text-tag text-red-text">
                    {m.jam} · Gagal terkirim ·{' '}
                    <button
                        type="button"
                        onClick={() =>
                            router.post(
                                kirimUlang.url(m.id),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="cursor-pointer border-none bg-transparent p-0 text-tag font-semibold text-red-text underline"
                    >
                        Kirim ulang
                    </button>
                </span>
            ) : (
                <span className="text-tag text-muted">
                    {m.jam}
                    {m.kirim === 'MENUNGGU' && ' · Mengirim…'}
                </span>
            )}
        </div>
    );
}

/**
 * "Jawab lewat Adiva" (once the WhatsApp command JAWAB): the supervisor's answer to the
 * question Adiva passed on, told to the customer in Adiva's words; numbers kept exactly.
 */
function JawabLewatAdiva({
    id,
    eskalasi,
}: {
    id: number;
    eskalasi: { pertanyaan: string; sejak: string };
}) {
    const form = useForm({ jawaban: '' });

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(jawab.url(id), {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className="flex flex-col gap-2 rounded-control border border-line px-3 py-2.5"
        >
            <div className="flex items-center gap-2">
                <span className="grow text-caption font-semibold">
                    Ditanyakan ke supervisor
                </span>
                <span className="text-tag text-muted">{eskalasi.sejak}</span>
            </div>
            <div className="text-caption leading-[1.45] text-ink-3">
                {eskalasi.pertanyaan}
            </div>
            <Textarea
                aria-label="Jawaban untuk customer"
                rows={3}
                placeholder="Tulis jawaban Anda; Adiva menyampaikannya ke customer"
                className="resize-none rounded-control px-2.5 py-2 text-body"
                value={form.data.jawaban}
                onChange={(e) => form.setData('jawaban', e.target.value)}
            />
            {form.errors.jawaban && (
                <span role="alert" className="text-caption text-red-text">
                    {form.errors.jawaban}
                </span>
            )}
            <Button
                type="submit"
                varian="primary"
                ukuran="sm"
                aria-disabled={
                    form.processing || form.data.jawaban.trim() === ''
                }
            >
                Jawab lewat Adiva
            </Button>
        </form>
    );
}

function Penilaian({
    percakapan: p,
    buka,
    onTutup,
}: {
    percakapan: Percakapan;
    /** below 1280px: shown over the chat from the right */
    buka: boolean;
    onTutup: () => void;
}) {
    return (
        <>
            {buka && (
                <div
                    aria-hidden="true"
                    onClick={onTutup}
                    className="fixed inset-0 z-30 bg-ink/30 motion-safe:animate-[pudar_150ms_ease-out] 2xl:hidden"
                />
            )}
            <aside
                id="info-customer"
                aria-label="Penilaian AI"
                onKeyDown={(e) => e.key === 'Escape' && onTutup()}
                className={cn(
                    'box-border flex w-[300px] shrink-0 flex-col gap-4 overflow-auto border-l border-line bg-surface p-[18px]',
                    'max-2xl:fixed max-2xl:inset-y-0 max-2xl:right-0 max-2xl:z-40 max-2xl:w-[340px] max-2xl:max-w-[90vw]',
                    'motion-safe:max-2xl:transition-[translate,visibility] motion-safe:max-2xl:duration-200',
                    buka
                        ? 'max-2xl:translate-x-0'
                        : 'max-2xl:invisible max-2xl:translate-x-full',
                )}
            >
                <div className="flex items-center gap-2 2xl:hidden">
                    <Link
                        href={pelanggan.url({ query: { pilih: p.id } })}
                        className="grow text-body font-semibold no-underline"
                    >
                        Buka profil {p.nama}
                    </Link>
                    <button
                        type="button"
                        aria-label="Tutup info customer"
                        onClick={onTutup}
                        className="flex size-8 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-muted hover:bg-line-softer"
                    >
                        <Ikon nama="tutup" />
                    </button>
                </div>
                <div className="flex flex-col gap-1">
                    <div className="text-body font-semibold text-ink">
                        Penilaian Adiva terakhir
                    </div>
                    <div className="text-body leading-[1.5] text-ink-2">
                        {p.ringkasan}
                    </div>
                </div>
                {p.alasanAdmin !== null && (
                    <div className="flex flex-col gap-[3px] rounded-control border border-note-amber-line bg-note-amber-bg px-3 py-2.5">
                        <div className="text-caption font-semibold text-[#92400E]">
                            Butuh admin
                        </div>
                        <div className="text-caption text-ink-3">
                            {p.alasanAdmin}
                        </div>
                    </div>
                )}
                {p.eskalasi !== null && (
                    <JawabLewatAdiva
                        key={p.id}
                        id={p.id}
                        eskalasi={p.eskalasi}
                    />
                )}
                <div className="grid grid-cols-2 gap-3">
                    {p.isian.map(([k, v]) => (
                        <div key={k} className="flex flex-col gap-[3px]">
                            <span className="text-tag text-muted">{k}</span>
                            <span className="text-body font-medium">{v}</span>
                        </div>
                    ))}
                </div>
                <div className="h-px bg-line-softer" />
                <CatatanTetap key={p.id} id={p.id} catatan={p.catatan} />
                <div className="flex flex-col gap-2">
                    <span className="text-body font-semibold">
                        Pesanan customer ini
                    </span>
                    {p.pesanan.map((o) => (
                        <Link
                            key={o.id}
                            href={detailPesanan.url(o.id)}
                            className="flex flex-col gap-1.5 rounded-control border border-line px-3 py-2.5 text-ink no-underline hover:border-line-strong hover:bg-subtle motion-safe:transition-colors"
                        >
                            <span className="flex items-center gap-2">
                                <span className="grow font-mono text-caption whitespace-nowrap">
                                    {o.id}
                                </span>
                                <span className="text-caption font-semibold whitespace-nowrap">
                                    {o.total}
                                </span>
                            </span>
                            <Status nada={o.status.nada} className="self-start">
                                {o.status.label}
                            </Status>
                        </Link>
                    ))}
                    {p.pesanan.length === 0 && (
                        <span className="text-caption text-muted">
                            Belum ada pesanan.
                        </span>
                    )}
                </div>
            </aside>
        </>
    );
}

/**
 * "Catatan tetap": written by admins, never overwritten by the system, read by Adiva on
 * every reply. "Ubah" turns it into a text box in place.
 */
function CatatanTetap({ id, catatan }: { id: number; catatan: string | null }) {
    const [mengubah, setMengubah] = useState(false);
    const form = useForm({ catatan_tetap: catatan ?? '' });
    const bolehUbah = usePage().props.panel?.akses.balas ?? false;

    return (
        <div className="flex flex-col gap-1.5">
            <div className="flex items-center">
                <span className="grow text-body font-semibold">
                    Catatan tetap
                </span>
                {!mengubah && (
                    <button
                        type="button"
                        aria-disabled={!bolehUbah}
                        onClick={() => bolehUbah && setMengubah(true)}
                        className="h-[30px] shrink-0 cursor-pointer rounded-control border border-line-strong bg-surface px-2.5 text-caption font-semibold whitespace-nowrap text-ink"
                    >
                        Ubah
                    </button>
                )}
            </div>
            {mengubah ? (
                <>
                    <Textarea
                        aria-label="Catatan tetap"
                        rows={5}
                        value={form.data.catatan_tetap}
                        onChange={(e) =>
                            form.setData('catatan_tetap', e.target.value)
                        }
                    />
                    {form.errors.catatan_tetap && (
                        <span
                            role="alert"
                            className="text-caption text-red-text"
                        >
                            {form.errors.catatan_tetap}
                        </span>
                    )}
                    <div className="flex gap-2">
                        <Button
                            varian="primary"
                            ukuran="sm"
                            aria-disabled={form.processing}
                            onClick={() =>
                                form.patch(ubahCatatan.url(id), {
                                    preserveScroll: true,
                                    onSuccess: () => setMengubah(false),
                                })
                            }
                        >
                            Simpan
                        </Button>
                        <Button
                            ukuran="sm"
                            onClick={() => {
                                form.reset();
                                setMengubah(false);
                            }}
                        >
                            Batal
                        </Button>
                    </div>
                </>
            ) : (
                <div className="box-border min-h-[56px] rounded-control border border-line bg-surface px-2.5 py-2 text-body leading-[1.45] whitespace-pre-line text-ink">
                    {catatan || 'Belum ada catatan.'}
                </div>
            )}
            <span className="text-tag text-muted">
                Ditulis admin, tidak pernah ditimpa sistem. Adiva ikut
                membacanya.
            </span>
        </div>
    );
}
