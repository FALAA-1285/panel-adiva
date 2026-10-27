import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Count } from '@/components/ui/count';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';
import { cari as ruteCari, notifikasi, pelanggan } from '@/routes';
import { detail as detailPesanan } from '@/routes/pesanan';
import type { Panel } from '@/types';

type Saran = { judul: string; sub: string | null; href: string };
type HasilCari = { customer: Saran[]; chat: Saran[]; pesanan: Saran[] };

const KELOMPOK: [keyof HasilCari, string][] = [
    ['pesanan', 'Pesanan'],
    ['chat', 'Chat'],
    ['customer', 'Customer'],
];

type TopbarProps = {
    panel: Panel;
    /** the sidebar shows icons only */
    ciut?: boolean;
    onCiutkan?: () => void;
    /** below 1024px: opens the sidebar drawer (not in the design) */
    onMenu?: () => void;
    laciTerbuka?: boolean;
};

/** Typing in a field: page shortcuts stay quiet. */
export function sedangMengetik(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;

    return (
        !!el &&
        (el.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))
    );
}

export function Topbar({
    panel,
    ciut = false,
    onCiutkan,
    onMenu,
    laciTerbuka = false,
}: TopbarProps) {
    const n = panel.hitungan.notifikasi;
    const [cari, setCari] = useState('');
    // phones: the search field opens over the bar
    const [cariTerbuka, setCariTerbuka] = useState(false);
    const isian = useRef<HTMLInputElement>(null);
    // suggestions while typing; -1 = none highlighted (Enter searches as before)
    const [hasil, setHasil] = useState<HasilCari | null>(null);
    const [sorot, setSorot] = useState(-1);
    const daftar = hasil ? KELOMPOK.flatMap(([k]) => hasil[k]) : [];

    useEffect(() => {
        const kata = cari.trim();

        if (kata.length < 2) {
            setHasil(null);

            return;
        }

        const batal = new AbortController();
        const tunda = window.setTimeout(() => {
            fetch(ruteCari.url({ query: { q: kata } }), {
                headers: { Accept: 'application/json' },
                signal: batal.signal,
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((h: HasilCari | null) => {
                    setHasil(h);
                    setSorot(-1);
                })
                .catch(() => {});
        }, 200);

        return () => {
            window.clearTimeout(tunda);
            batal.abort();
        };
    }, [cari]);

    const buka = (href: string) => {
        setHasil(null);
        setCari('');
        setCariTerbuka(false);
        router.visit(href);
    };

    // ⌘K / Ctrl+K from anywhere, or "/" outside a field, puts the cursor in the search box
    useEffect(() => {
        const fokus = (e: KeyboardEvent) => {
            const pintas =
                ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') ||
                (e.key === '/' && !sedangMengetik(e.target));

            if (pintas) {
                e.preventDefault();
                setCariTerbuka(true);
                isian.current?.focus();
            }
        };

        document.addEventListener('keydown', fokus);

        return () => document.removeEventListener('keydown', fokus);
    }, []);

    useEffect(() => {
        if (cariTerbuka) {
            isian.current?.focus();
        }
    }, [cariTerbuka]);

    // "ORD-…" opens that order; anything else is looked up on Pelanggan (name, city, number)
    const jalankan = () => {
        const kata = cari.trim();

        if (kata === '') {
            return;
        }

        setCariTerbuka(false);
        router.visit(
            /^ord-/i.test(kata)
                ? detailPesanan.url(kata.toUpperCase())
                : pelanggan.url({ query: { cari: kata } }),
        );
    };

    const tombolIkon =
        'flex size-8.5 shrink-0 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-muted hover:bg-line-softer hover:text-ink motion-safe:transition-colors max-md:size-11';

    return (
        <div className="relative box-border flex h-16 w-full shrink-0 items-center gap-3 border-b border-line bg-surface pr-6 pl-5 font-sans text-ink max-md:gap-1.5 max-md:pr-3 max-md:pl-2">
            <button
                type="button"
                aria-label={ciut ? 'Lebarkan sidebar' : 'Ciutkan sidebar'}
                aria-expanded={!ciut}
                aria-keyshortcuts="["
                onClick={onCiutkan}
                className={cn(tombolIkon, 'max-lg:hidden')}
            >
                <Ikon nama="sidebar" ukuran={18} />
            </button>
            <button
                type="button"
                aria-label="Buka menu"
                aria-controls="menu-utama"
                aria-expanded={laciTerbuka}
                onClick={onMenu}
                className={cn(tombolIkon, 'lg:hidden')}
            >
                <Ikon nama="menu" ukuran={20} />
            </button>
            <label
                className={cn(
                    'fokus-bingkai relative box-border flex h-9 w-[380px] items-center gap-2 rounded-control border border-line bg-latar pr-1.5 pl-[11px] text-muted',
                    'focus-within:border-line-strong focus-within:bg-surface motion-safe:transition-colors',
                    'max-lg:w-auto max-lg:max-w-[380px] max-lg:grow',
                    // phones: hidden behind the search icon, then over the whole bar
                    cariTerbuka
                        ? 'max-md:absolute max-md:inset-x-2 max-md:z-10 max-md:max-w-none'
                        : 'max-md:hidden',
                )}
            >
                <Ikon nama="cari" tebal={2} />
                <input
                    ref={isian}
                    type="search"
                    role="combobox"
                    aria-label="Cari"
                    aria-keyshortcuts="Control+K /"
                    aria-autocomplete="list"
                    aria-expanded={daftar.length > 0}
                    aria-controls="saran-cari"
                    aria-activedescendant={
                        sorot >= 0 ? `saran-${sorot}` : undefined
                    }
                    value={cari}
                    onChange={(e) => setCari(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowDown' && daftar.length > 0) {
                            e.preventDefault();
                            setSorot((s) => (s + 1) % daftar.length);
                        }

                        if (e.key === 'ArrowUp' && daftar.length > 0) {
                            e.preventDefault();
                            setSorot((s) =>
                                s <= 0 ? daftar.length - 1 : s - 1,
                            );
                        }

                        if (e.key === 'Enter') {
                            if (sorot >= 0 && daftar[sorot]) {
                                buka(daftar[sorot].href);
                            } else {
                                jalankan();
                            }
                        }

                        if (e.key === 'Escape') {
                            setHasil(null);
                            setCariTerbuka(false);
                            e.currentTarget.blur();
                        }
                    }}
                    onBlur={() => {
                        // after a click on a suggestion has been handled
                        window.setTimeout(() => setHasil(null), 150);
                        if (cari.trim() === '') {
                            setCariTerbuka(false);
                        }
                    }}
                    placeholder="Cari nama customer, nomor, atau ORD-…"
                    // px-0.5 py-px = the browser's default input padding the design relies on
                    className="min-w-0 grow border-none bg-transparent px-0.5 py-px text-body text-ink outline-none"
                />
                <kbd className="rounded-tag border border-line bg-surface px-1.5 py-px font-mono text-tag text-muted max-md:hidden">
                    ⌘K
                </kbd>
                {hasil && cari.trim().length >= 2 && (
                    <div
                        id="saran-cari"
                        role="listbox"
                        aria-label="Saran pencarian"
                        className="absolute inset-x-0 top-full z-30 mt-1.5 max-h-[420px] overflow-y-auto rounded-card border border-line bg-surface py-1.5 text-left shadow-lg"
                        // keep the field focused while a suggestion is clicked
                        onMouseDown={(e) => e.preventDefault()}
                    >
                        {daftar.length === 0 ? (
                            <div className="px-3.5 py-3 text-body text-muted">
                                Tidak ada yang cocok. Tekan Enter untuk mencari
                                di Pelanggan.
                            </div>
                        ) : (
                            KELOMPOK.filter(([k]) => hasil[k].length > 0).map(
                                ([k, label]) => (
                                    <div
                                        key={k}
                                        role="group"
                                        aria-label={label}
                                    >
                                        <div className="px-3.5 pt-2 pb-1 text-tag font-semibold tracking-[0.03em] text-muted uppercase">
                                            {label}
                                        </div>
                                        {hasil[k].map((s) => {
                                            const i = daftar.indexOf(s);

                                            return (
                                                <div
                                                    key={s.href}
                                                    id={`saran-${i}`}
                                                    role="option"
                                                    aria-selected={i === sorot}
                                                    onClick={() => buka(s.href)}
                                                    onMouseEnter={() =>
                                                        setSorot(i)
                                                    }
                                                    className={cn(
                                                        'flex cursor-pointer flex-col gap-px px-3.5 py-2',
                                                        i === sorot &&
                                                            'bg-selected',
                                                    )}
                                                >
                                                    <span className="truncate text-body font-medium text-ink">
                                                        {s.judul}
                                                    </span>
                                                    {s.sub && (
                                                        <span className="truncate text-caption text-muted">
                                                            {s.sub}
                                                        </span>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ),
                            )
                        )}
                    </div>
                )}
            </label>
            <span className="grow" />
            <button
                type="button"
                aria-label="Cari"
                onClick={() => setCariTerbuka(true)}
                className={cn(tombolIkon, 'md:hidden')}
            >
                <Ikon nama="cari" ukuran={18} tebal={2} />
            </button>
            <span className="text-caption whitespace-nowrap text-muted max-md:hidden">
                {panel.waktu}
            </span>
            <span className="h-5 w-px bg-line max-md:hidden" />
            <Link
                href={notifikasi.url()}
                aria-label={`Notifikasi: ${n} belum dibaca`}
                className="flex h-8.5 items-center gap-1.5 rounded-control px-2 text-body font-medium text-ink-2 no-underline hover:bg-line-softer hover:text-ink motion-safe:transition-colors max-md:h-11"
            >
                <Ikon nama="lonceng" ukuran={18} />
                {n > 0 && <Count n={n} kuat />}
            </Link>
        </div>
    );
}
