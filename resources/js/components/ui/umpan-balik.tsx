import { router } from '@inertiajs/react';
import type { ActiveVisit } from '@inertiajs/core';
import { useEffect, useRef, useState } from 'react';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

/*
 * Feedback after an action (not in the design; built from its tokens):
 * - a short message ("toast") at the bottom right, full width on phones;
 * - while a change is being sent, the button that sent it waits and further clicks
 *   are ignored, so ACC or "Kirim" can never go twice.
 */

type Nada = 'ok' | 'galat';
/** One button in the message, e.g. "Urungkan" after a delete. */
type Aksi = { label: string; jalankan: () => void };
type Pesan = { id: number; teks: string; nada: Nada; aksi?: Aksi };

const EVENT = 'adiva:toast';

/** Show a message from anywhere (e.g. after a useHttp call). */
export function toast(teks: string, nada: Nada = 'ok', aksi?: Aksi): void {
    window.dispatchEvent(
        new CustomEvent(EVENT, { detail: { teks, nada, aksi } }),
    );
}

const mengubah = (visit: Pick<ActiveVisit, 'method'>) => visit.method !== 'get';

export function UmpanBalik() {
    const [pesan, setPesan] = useState<Pesan[]>([]);
    const urut = useRef(0);

    useEffect(() => {
        const tambah = (teks: string, nada: Nada, aksi?: Aksi) => {
            const id = ++urut.current;
            setPesan((p) => [...p.slice(-2), { id, teks, nada, aksi }]);
            window.setTimeout(
                () => setPesan((p) => p.filter((x) => x.id !== id)),
                aksi ? 8000 : nada === 'galat' ? 6000 : 4000,
            );
        };

        const dariLuar = (e: Event) => {
            const { teks, nada, aksi } = (
                e as CustomEvent<{ teks: string; nada: Nada; aksi?: Aksi }>
            ).detail;
            tambah(teks, nada, aksi);
        };

        // the button that started a change, and whether one is on its way
        let tombol: HTMLElement | null = null;
        let berjalan = false;
        const catatKlik = (e: MouseEvent) => {
            const t = (e.target as HTMLElement | null)?.closest<HTMLElement>(
                'button, [role="button"], a[as="button"]',
            );

            if (berjalan && t && !t.closest('[data-bebas-klik]')) {
                e.preventDefault();
                e.stopPropagation();

                return;
            }

            tombol = t ?? null;
        };

        const lepas = [
            router.on('start', (e) => {
                if (!mengubah(e.detail.visit)) {
                    return;
                }

                berjalan = true;
                tombol?.setAttribute('data-memuat', '');
                tombol?.setAttribute('aria-busy', 'true');
            }),
            router.on('finish', (e) => {
                if (!mengubah(e.detail.visit)) {
                    return;
                }

                berjalan = false;
                document.querySelectorAll('[data-memuat]').forEach((el) => {
                    el.removeAttribute('data-memuat');
                    el.removeAttribute('aria-busy');
                });
                tombol = null;
            }),
            // a change went through: the server's own words (Inertia::flash('toast')), else
            // "Tersimpan"; `false` when the page shows the result itself (Uji tanya Adiva)
            router.on('success', (e) => {
                if (!berjalan) {
                    return;
                }

                const kabar = (
                    e.detail.page.flash as { toast?: unknown } | undefined
                )?.toast;

                if (kabar !== false) {
                    tambah(
                        typeof kabar === 'string' && kabar !== ''
                            ? kabar
                            : 'Tersimpan.',
                        'ok',
                    );
                }
            }),
            router.on('error', () => {
                if (berjalan) {
                    tambah('Periksa isian yang ditandai.', 'galat');
                }
            }),
            router.on('httpException', () => {
                tambah(
                    'Server tidak menjawab dengan benar. Coba lagi.',
                    'galat',
                );
            }),
            router.on('networkError', () => {
                tambah(
                    'Koneksi terputus, perubahan belum terkirim. Coba lagi.',
                    'galat',
                );
            }),
        ];

        window.addEventListener(EVENT, dariLuar);
        document.addEventListener('click', catatKlik, true);

        return () => {
            lepas.forEach((f) => f());
            window.removeEventListener(EVENT, dariLuar);
            document.removeEventListener('click', catatKlik, true);
        };
    }, []);

    return (
        <div
            role="status"
            aria-live="polite"
            data-bebas-klik
            className="pointer-events-none fixed right-5 bottom-5 z-50 flex w-[340px] flex-col gap-2 max-md:inset-x-3 max-md:bottom-3 max-md:w-auto"
        >
            {pesan.map((p) => (
                <div
                    key={p.id}
                    className={cn(
                        'pointer-events-auto flex items-start gap-2.5 rounded-control border px-3.5 py-2.5 text-body',
                        'motion-safe:animate-[muncul_160ms_ease-out]',
                        p.nada === 'ok'
                            ? 'border-ok-line bg-ok-bg text-ok-text'
                            : 'border-note-red-line bg-note-red-bg text-note-red-text',
                    )}
                >
                    <Ikon
                        nama={p.nada === 'ok' ? 'centang' : 'info'}
                        className="mt-px shrink-0"
                    />
                    <span className="grow leading-[1.45]">{p.teks}</span>
                    {p.aksi && (
                        <button
                            type="button"
                            onClick={() => {
                                p.aksi?.jalankan();
                                setPesan((x) => x.filter((y) => y.id !== p.id));
                            }}
                            className="-my-0.5 shrink-0 cursor-pointer rounded-tag border-none bg-transparent px-1.5 py-0.5 font-semibold text-current underline underline-offset-2"
                        >
                            {p.aksi.label}
                        </button>
                    )}
                    <button
                        type="button"
                        aria-label="Tutup pesan"
                        onClick={() =>
                            setPesan((x) => x.filter((y) => y.id !== p.id))
                        }
                        className="-mt-0.5 -mr-1 flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-tag border-none bg-transparent text-current opacity-70 hover:opacity-100"
                    >
                        <Ikon nama="tutup" ukuran={14} />
                    </button>
                </div>
            ))}
        </div>
    );
}

const PINTASAN: [string, string][] = [
    ['/  atau  Ctrl K', 'Cari customer, nomor, atau ORD-…'],
    [
        'J  /  K',
        'Baris berikut / sebelumnya (Percakapan, Pesanan, Pelanggan, Notifikasi)',
    ],
    ['R', 'Ke kotak balasan (Percakapan)'],
    ['[', 'Ciutkan / lebarkan sidebar'],
    ['Esc', 'Tutup menu, panel, atau pencarian'],
    ['?', 'Tampilkan daftar ini'],
];

/** "?": the keyboard shortcuts (not in the design). */
export function DaftarPintasan({ onTutup }: { onTutup: () => void }) {
    const tutup = useRef<HTMLButtonElement>(null);

    useEffect(() => tutup.current?.focus(), []);

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-ink/30 p-4 motion-safe:animate-[pudar_120ms_ease-out]"
            onClick={onTutup}
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="judul-pintasan"
                onClick={(e) => e.stopPropagation()}
                onKeyDown={(e) => e.key === 'Escape' && onTutup()}
                className="w-full max-w-[460px] rounded-card border border-line bg-surface font-sans text-ink"
            >
                <div className="flex items-center gap-2.5 border-b border-line px-[18px] py-3.5">
                    <Ikon nama="keyboard" ukuran={18} />
                    <h2
                        id="judul-pintasan"
                        className="m-0 grow text-panel font-semibold"
                    >
                        Pintasan keyboard
                    </h2>
                    <button
                        ref={tutup}
                        type="button"
                        aria-label="Tutup"
                        onClick={onTutup}
                        className="flex size-8 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-muted hover:bg-line-softer hover:text-ink"
                    >
                        <Ikon nama="tutup" />
                    </button>
                </div>
                <dl className="m-0 flex flex-col px-[18px] py-2">
                    {PINTASAN.map(([tombol, arti]) => (
                        <div
                            key={tombol}
                            className="grid grid-cols-[120px_minmax(0,1fr)] items-baseline gap-3 border-b border-line-soft py-2.5 last:border-b-0"
                        >
                            <dt>
                                <kbd className="rounded-tag border border-line bg-subtle px-1.5 py-px font-mono text-caption text-ink-2">
                                    {tombol}
                                </kbd>
                            </dt>
                            <dd className="m-0 text-body text-ink-3">{arti}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </div>
    );
}
