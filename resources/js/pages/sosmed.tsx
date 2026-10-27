import { Head, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { AppLayout } from '@/components/ui/app-layout';
import { Button } from '@/components/ui/button';
import { Field, Input, Textarea, Toggle } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { PageHeader } from '@/components/ui/page-header';
import { Panel } from '@/components/ui/panel';
import { Status } from '@/components/ui/status';
import type { Nada } from '@/components/ui/status';
import { Avatar } from '@/components/ui/teks';
import { sedangMengetik } from '@/components/ui/topbar';
import { cocok, LAYAR } from '@/hooks/use-layar';
import { cn } from '@/lib/utils';
import { sosmed as ruteSosmed } from '@/routes';
import {
    balas,
    jeda,
    lanjut,
    sambungan as simpanSambungan,
} from '@/routes/sosmed';
import { uji as ujiSambungan } from '@/routes/sosmed/sambungan';

type Platform = 'IG' | 'FB';

type Baris = {
    id: number;
    nama: string;
    inisial: string;
    platform: Platform;
    teks: string;
    jam: string;
    status: { label: string; nada: Nada } | null;
};

type Pesan = {
    id: number;
    jenis: 'customer' | 'adiva' | 'admin' | 'sistem';
    oleh: string | null;
    teks: string | null;
    jam: string;
    kirim: 'MENUNGGU' | 'GAGAL' | null;
    galat: string | null;
};

type Kontak = Baris & {
    username: string | null;
    foto: string | null;
    profil: string | null;
    dijeda: boolean;
    info: [string, string][];
    pesan: Pesan[];
};

type Sambungan = {
    boleh: boolean;
    tersambung: boolean;
    tokenTersimpan: boolean;
    rahasiaTersimpan: boolean;
    nilai: Record<
        | 'META_PAGE_ID'
        | 'META_IG_ID'
        | 'META_APP_ID'
        | 'META_VERIFY_TOKEN'
        | 'SOSMED_JEDA_JAM'
        | 'SOSMED_BOT_IG'
        | 'SOSMED_BOT_FB',
        string
    >;
    webhook: string | null;
};

type Props = {
    tab: { kode: string; label: string; n: number }[];
    aktifTab: string;
    cari: string;
    daftar: Baris[];
    kontak: Kontak | null;
    sambungan: Sambungan;
    bolehBalas: boolean;
};

const PLATFORM: Record<Platform, { label: string; nada: Nada }> = {
    IG: { label: 'Instagram', nada: 'violet' },
    FB: { label: 'Facebook', nada: 'blue' },
};

const query = (tab: string, cari: string, pilih?: number) => ({
    tab: tab === 'semua' ? undefined : tab,
    cari: cari || undefined,
    pilih,
});

/**
 * Instagram & Facebook (not in the design; built from Percakapan's parts): the DMs Chatbot
 * Sosmed answers, replied to and paused from the panel, and the page's link to Meta.
 */
export default function HalamanSosmed({
    tab,
    aktifTab,
    cari: cariAwal,
    daftar,
    kontak,
    sambungan,
    bolehBalas,
}: Props) {
    const [cari, setCari] = useState(cariAwal);
    const [dipilih, setDipilih] = useState(kontak?.id);
    // Daily DM workspace is always the default view; connection setup is opened on demand
    const [aturSambungan, setAturSambungan] = useState(false);
    const pertama = useRef(true);
    const lihatUtas = new URLSearchParams(
        usePage().url.split('?')[1] ?? '',
    ).has('pilih');

    usePoll(5000, { only: ['tab', 'daftar', 'kontak', 'panel'] });

    useEffect(() => {
        if (pertama.current) {
            pertama.current = false;

            return;
        }

        const tunda = setTimeout(
            () =>
                router.get(
                    ruteSosmed.url({ query: query(aktifTab, cari) }),
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
    }, [cari, aktifTab]);

    const pilih = (id: number) => {
        setDipilih(id);
        router.get(
            ruteSosmed.url({ query: query(aktifTab, cari, id) }),
            {},
            {
                only: ['kontak'],
                preserveState: true,
                preserveScroll: true,
                replace: cocok(LAYAR.sedang),
            },
        );
    };

    const keDaftar = () =>
        router.get(
            ruteSosmed.url({ query: query(aktifTab, cari) }),
            {},
            { only: ['kontak'], preserveState: true, preserveScroll: true },
        );

    // J / K: next or previous contact; R: the reply box
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
                document.getElementById('balasan-sosmed')?.focus();
            }
        };

        document.addEventListener('keydown', tekan);

        return () => document.removeEventListener('keydown', tekan);
    });

    return (
        <AppLayout active="sosmed" penuhLayar>
            <Head title="Instagram & Facebook" />
            <PageHeader
                judul="Instagram & Facebook"
                sub="DM dari Instagram dan Facebook dijawab Adiva. Balas, jeda, atau lanjutkan bot dari sini."
                aksi={
                    <>
                        <span
                            className={cn(
                                'flex items-center gap-1.5 text-caption font-medium whitespace-nowrap',
                                sambungan.tersambung
                                    ? 'text-green-text'
                                    : 'text-amber-text',
                            )}
                        >
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    sambungan.tersambung
                                        ? 'bg-green-dot'
                                        : 'bg-amber-dot',
                                )}
                            />
                            {sambungan.tersambung
                                ? 'Token Meta tersimpan'
                                : 'Belum tersambung ke Meta'}
                        </span>
                        {sambungan.boleh && (
                            <Button
                                aria-expanded={aturSambungan}
                                onClick={() => setAturSambungan(!aturSambungan)}
                            >
                                <Ikon nama="pengaturan" ukuran={15} tebal={2} />
                                Sambungan
                            </Button>
                        )}
                    </>
                }
                tab={tab.map((t) => ({
                    label: t.label,
                    n: t.n,
                    aktif: t.kode === aktifTab,
                    href: ruteSosmed.url({ query: query(t.kode, cari) }),
                }))}
            />
            {aturSambungan && sambungan.boleh && (
                <div className="mx-8 mt-5 max-md:mx-4">
                    <FormSambungan
                        sambungan={sambungan}
                        onTutup={() => setAturSambungan(false)}
                    />
                </div>
            )}
            <div className="mx-8 mt-5 mb-6 flex min-h-[420px] grow overflow-hidden rounded-card border border-line max-md:mx-0 max-md:mt-3 max-md:mb-0 max-md:rounded-none max-md:border-x-0 max-md:border-b-0">
                <section
                    aria-label="Daftar kontak"
                    className={cn(
                        'box-content flex w-[300px] shrink-0 flex-col border-r border-line bg-surface',
                        'max-lg:w-full max-lg:border-r-0',
                        lihatUtas && 'max-lg:hidden',
                    )}
                >
                    <div className="border-b border-line-softer px-4 pt-3.5 pb-3">
                        <label className="fokus-bingkai box-border flex h-10 items-center gap-2 rounded-control border border-line-control px-3 text-muted">
                            <Ikon nama="cari" tebal={2} />
                            <input
                                type="search"
                                aria-label="Cari kontak"
                                placeholder="Cari nama, username, atau pesan"
                                className="grow border-none bg-transparent text-body text-ink outline-none"
                                value={cari}
                                onChange={(e) => setCari(e.target.value)}
                            />
                        </label>
                    </div>
                    <div className="grow overflow-auto">
                        {daftar.length === 0 && (
                            <div className="px-4 py-6 text-center text-body text-muted">
                                {sambungan.tersambung
                                    ? 'Belum ada DM di tab ini.'
                                    : 'Belum ada DM. Sambungkan akun Meta, lalu aktifkan workflow Chatbot Sosmed di n8n.'}
                            </div>
                        )}
                        {daftar.map((b) => (
                            <BarisKontak
                                key={b.id}
                                baris={b}
                                terpilih={b.id === dipilih}
                                onPilih={() => pilih(b.id)}
                            />
                        ))}
                    </div>
                </section>

                {kontak ? (
                    <Utas
                        key={kontak.id}
                        kontak={kontak}
                        bolehBalas={bolehBalas}
                        tampil={lihatUtas}
                        onKembali={keDaftar}
                    />
                ) : (
                    <div className="flex grow items-center justify-center bg-subtle px-6 text-center text-body text-muted max-lg:hidden">
                        Pilih kontak di kiri untuk melihat isi chatnya.
                    </div>
                )}
                {kontak && <InfoKontak kontak={kontak} />}
            </div>
        </AppLayout>
    );
}

function BarisKontak({
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
                    ? 'bg-selected shadow-[inset_3px_0_0_var(--color-primary)]'
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
                    <span className="text-tag text-muted">{b.jam}</span>
                </span>
                <span className="truncate text-caption text-ink-3">
                    {b.teks}
                </span>
                <span className="flex flex-wrap items-center gap-2 pt-px">
                    <Status
                        nada={PLATFORM[b.platform].nada}
                        className="text-caption"
                    >
                        {PLATFORM[b.platform].label}
                    </Status>
                    {b.status && (
                        <Status nada={b.status.nada} className="text-caption">
                            {b.status.label}
                        </Status>
                    )}
                </span>
            </span>
        </button>
    );
}

function Utas({
    kontak: k,
    bolehBalas,
    tampil,
    onKembali,
}: {
    kontak: Kontak;
    bolehBalas: boolean;
    tampil: boolean;
    onKembali: () => void;
}) {
    const isi = useRef<HTMLDivElement>(null);
    const jumlah = useRef(k.pesan.length);
    const form = useForm({ teks: '' });

    // the newest message in view, also when polling brings one
    useLayoutEffect(() => {
        const el = isi.current;

        if (
            el &&
            (jumlah.current === k.pesan.length ||
                el.scrollHeight - el.scrollTop - el.clientHeight < 160)
        ) {
            el.scrollTop = el.scrollHeight;
        }

        jumlah.current = k.pesan.length;
    }, [k.pesan.length]);

    const kirim = () =>
        form.post(balas.url(k.id), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });

    return (
        <section
            aria-label="Isi chat"
            className={cn(
                'relative flex min-w-0 grow flex-col bg-subtle',
                !tampil && 'max-lg:hidden',
            )}
        >
            <div className="box-border flex h-[68px] shrink-0 items-center gap-3 border-b border-line bg-surface px-5 max-md:gap-2 max-md:px-3">
                <button
                    type="button"
                    aria-label="Kembali ke daftar kontak"
                    onClick={onKembali}
                    className="-ml-1 flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-ink-2 hover:bg-line-softer lg:hidden"
                >
                    <Ikon nama="kembali" ukuran={20} />
                </button>
                <Avatar inisial={k.inisial} ukuran={36} ukuranTeks={13} />
                <div className="flex min-w-0 grow flex-col gap-0.5">
                    <div className="truncate text-panel font-semibold">
                        {k.nama}
                    </div>
                    <div className="flex min-w-0 items-center gap-2 text-caption">
                        <Status nada={PLATFORM[k.platform].nada}>
                            {PLATFORM[k.platform].label}
                        </Status>
                        {k.username &&
                            (k.profil ? (
                                <a
                                    href={k.profil}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="truncate font-medium no-underline hover:underline"
                                >
                                    @{k.username}
                                </a>
                            ) : (
                                <span className="truncate text-muted">
                                    @{k.username}
                                </span>
                            ))}
                    </div>
                </div>
                {bolehBalas &&
                    (k.dijeda ? (
                        <Button
                            varian="primary"
                            className="max-md:px-2.5"
                            onClick={() =>
                                router.post(
                                    lanjut.url(k.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Ikon nama="putar" ukuran={15} tebal={2} />
                            Lanjutkan bot
                        </Button>
                    ) : (
                        <Button
                            className="max-md:px-2.5"
                            onClick={() =>
                                router.post(
                                    jeda.url(k.id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Ikon nama="jeda" ukuran={15} tebal={2} />
                            Jeda bot
                        </Button>
                    ))}
            </div>

            <div
                ref={isi}
                className="flex grow flex-col gap-3 overflow-auto px-6 py-5 max-md:px-3"
            >
                {k.pesan.length === 0 && (
                    <div className="self-center text-body text-muted">
                        Isi chat tercatat sejak workflow Chatbot Sosmed mencatat
                        ke panel.
                    </div>
                )}
                {k.pesan.map((m) => (
                    <Gelembung key={m.id} pesan={m} />
                ))}
            </div>

            {bolehBalas && (
                <div className="flex flex-col gap-2 border-t border-line bg-surface px-5 py-3 max-md:px-3">
                    <span className="text-caption text-muted">
                        Membalas di sini menjeda Adiva untuk kontak ini. Meta
                        hanya mengizinkan balasan sampai 24 jam setelah pesan
                        terakhir customer.
                    </span>
                    <div className="flex items-end gap-2">
                        <Textarea
                            id="balasan-sosmed"
                            aria-label="Balasan"
                            rows={2}
                            placeholder="Tulis balasan sebagai admin…"
                            className="grow resize-none rounded-card px-3 py-2.5 text-body"
                            value={form.data.teks}
                            onChange={(e) =>
                                form.setData('teks', e.target.value)
                            }
                            onKeyDown={(e) => {
                                if (
                                    e.key === 'Enter' &&
                                    (e.ctrlKey || e.metaKey)
                                ) {
                                    e.preventDefault();
                                    kirim();
                                }
                            }}
                        />
                        <Button
                            varian="primary"
                            aria-disabled={
                                form.processing || form.data.teks.trim() === ''
                            }
                            onClick={() =>
                                form.data.teks.trim() !== '' && kirim()
                            }
                        >
                            <Ikon nama="kirim" tebal={2} />
                            Kirim
                        </Button>
                    </div>
                    {form.errors.teks && (
                        <span
                            role="alert"
                            className="text-caption text-red-text"
                        >
                            {form.errors.teks}
                        </span>
                    )}
                </div>
            )}
        </section>
    );
}

function Gelembung({ pesan: m }: { pesan: Pesan }) {
    if (m.jenis === 'sistem') {
        return (
            <div className="self-center px-3 text-center text-caption text-muted">
                {m.teks}
            </div>
        );
    }

    if (m.jenis === 'customer') {
        return (
            <div className="flex max-w-[460px] flex-col gap-1 self-start max-md:max-w-[85%]">
                <div className="rounded-[12px_12px_12px_4px] border border-line bg-surface px-3 py-2.5 text-body leading-[1.5] whitespace-pre-line">
                    {m.teks}
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
                    'rounded-[12px_12px_4px_12px] border px-3 py-2.5 text-body leading-[1.5] whitespace-pre-line',
                    adiva
                        ? 'border-[#DCE6FB] bg-[#EEF3FE]'
                        : 'border-[#E7E2FA] bg-[#F4F2FD]',
                )}
            >
                {m.teks}
            </div>
            <span
                className={cn(
                    'text-tag',
                    m.kirim === 'GAGAL' ? 'text-red-text' : 'text-muted',
                )}
                title={m.galat ?? undefined}
            >
                {m.jam}
                {m.kirim === 'MENUNGGU' && ' · mengirim…'}
                {m.kirim === 'GAGAL' &&
                    ` · Gagal terkirim${m.galat ? `: ${m.galat}` : ''}`}
            </span>
        </div>
    );
}

function InfoKontak({ kontak: k }: { kontak: Kontak }) {
    return (
        <aside
            aria-label="Info kontak"
            className="box-border flex w-[280px] shrink-0 flex-col gap-4 overflow-auto border-l border-line bg-surface p-[18px] max-xl:hidden"
        >
            <div className="text-body font-semibold">Info kontak</div>
            <div className="grid grid-cols-1 gap-3">
                {k.info.map(([label, nilai]) => (
                    <div key={label} className="flex flex-col gap-[3px]">
                        <span className="text-tag text-muted">{label}</span>
                        <span className="text-body font-medium">{nilai}</span>
                    </div>
                ))}
            </div>
        </aside>
    );
}

/**
 * Sambungan: the page's ids and switches; the token and the app secret are typed in and
 * never shown again.
 */
function FormSambungan({
    sambungan: s,
    onTutup,
}: {
    sambungan: Sambungan;
    onTutup?: () => void;
}) {
    const form = useForm({
        ...s.nilai,
        META_PAGE_TOKEN_BARU: '',
        META_APP_SECRET_BARU: '',
    });
    const [galatUji, setGalatUji] = useState<string | null>(null);

    const isian = (
        kunci:
            | 'META_PAGE_ID'
            | 'META_IG_ID'
            | 'META_APP_ID'
            | 'META_VERIFY_TOKEN',
        label: string,
        sub?: string,
    ) => (
        <Field label={label} htmlFor={kunci} hint={sub}>
            <Input
                id={kunci}
                name={`meta_field_${kunci.toLowerCase()}`}
                autoComplete="off"
                value={form.data[kunci]}
                onChange={(e) => form.setData(kunci, e.target.value)}
            />
            {form.errors[kunci] && (
                <span className="text-caption text-red-text">
                    {form.errors[kunci]}
                </span>
            )}
        </Field>
    );

    return (
        <Panel
            judul="Sambungan Meta"
            sub="Diisi dari Meta for Developers (aplikasi + halaman Facebook yang tertaut ke akun Instagram). Dipakai n8n dan panel."
            aksi={
                <div className="flex items-center gap-2">
                    <Button
                        ukuran="sm"
                        onClick={() =>
                            router.post(
                                ujiSambungan.url(),
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setGalatUji(null),
                                    onError: (e) =>
                                        setGalatUji(e.sambungan ?? null),
                                },
                            )
                        }
                    >
                        Uji sambungan
                    </Button>
                    {onTutup && (
                        <Button
                            ukuran="sm"
                            type="button"
                            onClick={onTutup}
                        >
                            Tutup
                        </Button>
                    )}
                </div>
            }
        >
            <form
                className="flex flex-col gap-4"
                autoComplete="off"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(simpanSambungan.url(), {
                        preserveScroll: true,
                        onSuccess: () =>
                            form.reset(
                                'META_PAGE_TOKEN_BARU',
                                'META_APP_SECRET_BARU',
                            ),
                    });
                }}
            >
                {/* Dummy inputs to prevent browser credential managers from autofilling saved email/password */}
                <input
                    type="text"
                    name="meta_prevent_autofill_email"
                    className="hidden"
                    tabIndex={-1}
                    autoComplete="off"
                />
                <input
                    type="password"
                    name="meta_prevent_autofill_pwd"
                    className="hidden"
                    tabIndex={-1}
                    autoComplete="new-password"
                />

                {galatUji && (
                    <span role="alert" className="text-body text-red-text">
                        {galatUji}
                    </span>
                )}
                <div className="grid grid-cols-3 gap-3 max-lg:grid-cols-2 max-md:grid-cols-1">
                    {isian('META_PAGE_ID', 'Page ID Facebook')}
                    {isian('META_IG_ID', 'ID akun Instagram')}
                    {isian(
                        'META_APP_ID',
                        'App ID',
                        'Kiriman dari panel & bot tidak dicatat dua kali',
                    )}
                    <Field
                        label="Page access token"
                        htmlFor="META_PAGE_TOKEN_BARU"
                        hint={
                            s.tokenTersimpan
                                ? 'Tersimpan · isi hanya untuk mengganti'
                                : 'Belum diisi'
                        }
                    >
                        <Input
                            id="META_PAGE_TOKEN_BARU"
                            name="meta_field_page_token"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.META_PAGE_TOKEN_BARU}
                            onChange={(e) =>
                                form.setData(
                                    'META_PAGE_TOKEN_BARU',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field
                        label="App secret"
                        htmlFor="META_APP_SECRET_BARU"
                        hint={
                            s.rahasiaTersimpan
                                ? 'Tersimpan · isi hanya untuk mengganti'
                                : 'Untuk memeriksa kiriman Meta'
                        }
                    >
                        <Input
                            id="META_APP_SECRET_BARU"
                            name="meta_field_app_secret"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.META_APP_SECRET_BARU}
                            onChange={(e) =>
                                form.setData(
                                    'META_APP_SECRET_BARU',
                                    e.target.value,
                                )
                            }
                        />
                    </Field>
                    {isian(
                        'META_VERIFY_TOKEN',
                        'Verify token webhook',
                        'Sama persis dengan yang diisi di Meta',
                    )}
                </div>
                <div className="flex flex-col gap-1 rounded-control border border-line-softer bg-subtle px-3.5 py-3">
                    <span className="text-caption font-semibold">
                        Alamat webhook (isi di Meta → Webhooks)
                    </span>
                    <span className="font-mono text-caption break-all text-ink-2">
                        {s.webhook ??
                            'Alamat webhook belum terkonfigurasi di server.'}
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-x-8 gap-y-3">
                    {(['IG', 'FB'] as const).map((p) => {
                        const kunci =
                            p === 'IG' ? 'SOSMED_BOT_IG' : 'SOSMED_BOT_FB';

                        return (
                            <label
                                key={p}
                                className="flex items-center gap-2.5 text-body"
                            >
                                <Toggle
                                    aria-label={`Adiva membalas ${PLATFORM[p].label}`}
                                    nyala={form.data[kunci] !== 'MATI'}
                                    onUbah={(nyala) =>
                                        form.setData(
                                            kunci,
                                            nyala ? 'AKTIF' : 'MATI',
                                        )
                                    }
                                />
                                Adiva membalas {PLATFORM[p].label}
                            </label>
                        );
                    })}
                    <label className="flex items-center gap-2 text-body">
                        Bot diam setelah admin membalas
                        <Input
                            aria-label="Jam bot diam setelah admin membalas"
                            inputMode="numeric"
                            className="h-9 w-16 text-center"
                            value={form.data.SOSMED_JEDA_JAM}
                            onChange={(e) =>
                                form.setData('SOSMED_JEDA_JAM', e.target.value)
                            }
                        />
                        jam
                    </label>
                    {form.errors.SOSMED_JEDA_JAM && (
                        <span className="text-caption text-red-text">
                            {form.errors.SOSMED_JEDA_JAM}
                        </span>
                    )}
                </div>
                <div>
                    <Button
                        type="submit"
                        varian="primary"
                        aria-disabled={form.processing}
                    >
                        Simpan sambungan
                    </Button>
                </div>
            </form>
        </Panel>
    );
}
