import { Link } from '@inertiajs/react';
import { Count } from '@/components/ui/count';
import { Ikon } from '@/components/ui/ikon';
import type { NamaIkon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';
import {
    broadcast,
    home,
    jeda,
    logout,
    monitoring,
    otomasi,
    pelanggan,
    pengaturan,
    percakapan,
    pesanan,
    pipeline,
    prompt,
    sosmed,
    tim,
} from '@/routes';
import type { Panel } from '@/types';

export type MenuAktif =
    | 'main'
    | 'inbox'
    | 'orders'
    | 'jeda'
    | 'pipeline'
    | 'customer'
    | 'broadcast'
    | 'sosmed'
    | 'katalog'
    | 'prompt'
    | 'otomasi'
    | 'settings'
    | 'monitoring'
    | 'tim'
    | 'aktivitas'
    | 'none';

type ItemMenu = {
    kode: Exclude<MenuAktif, 'none'>;
    label: string;
    href: string;
    ikon: NamaIkon;
    hitungan?: keyof Panel['hitungan'];
    kuat?: boolean;
    /** Hidden unless the role may open it. */
    akses?: keyof Panel['akses'];
};

// Page routes follow design/halaman.json; switch to Wayfinder as each page is built.
const grup: { judul: string; item: ItemMenu[] }[] = [
    {
        judul: 'Operasional',
        item: [
            {
                kode: 'main',
                label: 'Ringkasan',
                href: home.url(),
                ikon: 'ringkasan',
            },
            {
                kode: 'inbox',
                label: 'Percakapan',
                href: percakapan.url(),
                ikon: 'percakapan',
                hitungan: 'percakapan',
                kuat: true,
            },
            {
                kode: 'orders',
                label: 'Pesanan',
                href: pesanan.url(),
                ikon: 'pesanan',
                hitungan: 'pesanan',
            },
            {
                kode: 'jeda',
                label: 'Ambil alih chat',
                href: jeda.url(),
                ikon: 'jeda',
                hitungan: 'jeda',
            },
        ],
    },
    {
        judul: 'Pelanggan',
        item: [
            {
                kode: 'pipeline',
                label: 'Pipeline',
                href: pipeline.url(),
                ikon: 'pipeline',
            },
            {
                kode: 'customer',
                label: 'Pelanggan',
                href: pelanggan.url(),
                ikon: 'pelanggan',
            },
            {
                kode: 'broadcast',
                label: 'Broadcast',
                href: broadcast.url(),
                ikon: 'broadcast',
                akses: 'broadcast',
            },
            {
                kode: 'sosmed',
                label: 'Instagram & Facebook',
                href: sosmed.url(),
                ikon: 'sosmed',
                hitungan: 'sosmed',
                akses: 'sosmed',
            },
        ],
    },
    {
        judul: 'Adiva',
        item: [
            {
                kode: 'katalog',
                label: 'Katalog & pengetahuan',
                href: '/katalog',
                ikon: 'katalog',
            },
            {
                kode: 'prompt',
                label: 'Prompt Adiva',
                href: prompt.url(),
                ikon: 'prompt',
                akses: 'katalog',
            },
            {
                kode: 'otomasi',
                label: 'Otomasi',
                href: otomasi.url(),
                ikon: 'otomasi',
                akses: 'pengaturan',
            },
            {
                kode: 'settings',
                label: 'Pengaturan',
                href: pengaturan.url(),
                ikon: 'pengaturan',
                akses: 'pengaturan',
            },
        ],
    },
    {
        judul: 'Sistem',
        item: [
            {
                kode: 'monitoring',
                label: 'Monitoring',
                href: monitoring.url(),
                ikon: 'monitoring',
                hitungan: 'monitoring',
                akses: 'monitoring',
            },
            {
                kode: 'tim',
                label: 'Tim & akses',
                href: tim.url(),
                ikon: 'tim',
                akses: 'tim',
            },
            {
                kode: 'aktivitas',
                label: 'Log aktivitas',
                href: '/aktivitas',
                ikon: 'aktivitas',
                akses: 'aktivitas',
            },
        ],
    },
];

type SidebarProps = {
    active: MenuAktif;
    panel: Panel;
    /** "Ciutkan sidebar" on wide screens: icons only, 64px wide (not in the design; off by default). */
    ciut?: boolean;
    /** Kept for the component preview; the sidebar is full from 1024px unless collapsed. */
    lebar?: boolean;
    laci?: boolean;
    onTutupLaci?: () => void;
    /** Component preview (design/screens/Sidebar.png): no screen-size rules. */
    tetap?: boolean;
};

/**
 * Which parts show, as classes, so the first paint is right on every screen:
 * `penuh` = labels, counts and the status block; `ikon` = their icon-only stand-ins.
 */
function kelasMode(ciut: boolean, tetap: boolean) {
    if (tetap) {
        return { penuh: ciut ? 'hidden' : '', ikon: ciut ? '' : 'hidden' };
    }

    // from 1024px the menu shows its names unless collapsed (Audit: icons alone left
    // new staff guessing); below 1024px it is a drawer with names
    return {
        penuh: cn(ciut && 'lg:hidden'),
        ikon: cn(!ciut && 'lg:hidden', 'max-lg:hidden'),
    };
}

export function Sidebar({
    active,
    panel,
    ciut = false,
    laci = false,
    onTutupLaci,
    tetap = false,
}: SidebarProps) {
    const { hitungan, status, pengguna, akses } = panel;
    const mode = kelasMode(ciut, tetap);

    return (
        <nav
            id="menu-utama"
            aria-label="Menu utama"
            onKeyDown={(e) => e.key === 'Escape' && onTutupLaci?.()}
            className={cn(
                'box-border flex h-full shrink-0 flex-col gap-[18px] overflow-y-auto border-r border-line bg-latar px-3 pt-3 pb-3 font-sans text-ink-2',
                ciut ? 'w-16' : 'w-[248px]',
                !tetap && [
                    // the drawer: over the page, scrolls on short phones
                    'max-lg:fixed max-lg:inset-y-0 max-lg:left-0 max-lg:z-40 max-lg:w-[280px] max-lg:max-w-[85vw] max-lg:overflow-y-auto',
                    'motion-safe:max-lg:transition-[translate,visibility] motion-safe:max-lg:duration-200',
                    laci
                        ? 'max-lg:translate-x-0'
                        : 'max-lg:invisible max-lg:-translate-x-full',
                ],
            )}
        >
            <Link
                href={home.url()}
                aria-label="Bang Bana — ke Ringkasan"
                className={cn(
                    'flex items-center gap-2.5 rounded-ikon p-2 text-ink no-underline',
                    !ciut && 'bg-subtle',
                )}
            >
                <span
                    aria-hidden="true"
                    className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-linear-135 from-brand-from to-brand text-caption font-bold tracking-[-0.02em] text-white shadow-utama"
                >
                    BB
                </span>
                <span
                    className={cn('flex min-w-0 flex-col gap-px', mode.penuh)}
                >
                    <span className="text-panel font-semibold whitespace-nowrap">
                        Bang Bana
                    </span>
                    <span className="text-caption whitespace-nowrap text-muted">
                        Panel Adiva
                    </span>
                </span>
            </Link>

            {grup.map((g) => {
                const itemTampil = g.item.filter(
                    (item) => !item.akses || akses[item.akses],
                );
                if (itemTampil.length === 0) return null;

                return (
                    <div key={g.judul} className="flex flex-col gap-px">
                        <div
                            className={cn('mx-2.5 mb-1.5 h-px bg-line', mode.ikon)}
                        />
                        <div
                            className={cn(
                                'px-2.5 pb-1.5 text-caption font-medium text-muted',
                                mode.penuh,
                            )}
                        >
                            {g.judul}
                        </div>
                        {itemTampil.map((item) => {
                            const n = item.hitungan
                                ? hitungan[item.hitungan]
                                : 0;
                            const judulTooltip =
                                n > 0
                                    ? `${item.label} (${n} perlu tindakan)`
                                    : item.label;

                            return (
                                <Link
                                    key={item.kode}
                                    href={item.href}
                                    aria-current={
                                        item.kode === active
                                            ? 'page'
                                            : undefined
                                    }
                                    aria-label={judulTooltip}
                                    title={judulTooltip}
                                    className={cn(
                                        'relative flex h-9 items-center gap-2.5 rounded-nav px-2.5 text-body no-underline motion-safe:transition-colors motion-safe:duration-150',
                                        item.kode === active
                                            ? 'bg-nav-active font-semibold text-primary-ink'
                                            : 'bg-transparent font-medium text-ink-2 hover:bg-line-softer hover:text-ink',
                                        // the drawer: comfortable touch targets
                                        !tetap && 'max-lg:h-10',
                                    )}
                                >
                                    <Ikon nama={item.ikon} />
                                    <span className={cn('grow', mode.penuh)}>
                                        {item.label}
                                    </span>
                                    {n > 0 && (
                                        <>
                                            <span
                                                className={cn(
                                                    'flex',
                                                    mode.penuh,
                                                )}
                                            >
                                                <Count n={n} kuat={item.kuat} />
                                            </span>
                                            <span
                                                className={cn(
                                                    'absolute top-1 right-1 size-[7px] rounded-full bg-primary',
                                                    mode.ikon,
                                                )}
                                                title={`${n} perlu tindakan`}
                                            />
                                        </>
                                    )}
                                </Link>
                            );
                        })}
                    </div>
                );
            })}

            <div className="grow" />

            {(() => {
                const aktif = status.adivaAktif ?? true;
                const statusTeks = aktif ? 'Adiva sedang aktif' : 'Adiva nonaktif';

                return (
                    <>
                        <div
                            className={cn(
                                'flex justify-center border-t border-line py-3',
                                mode.ikon,
                            )}
                            title={statusTeks}
                        >
                            <span
                                className={cn(
                                    'size-[7px] rounded-full',
                                    aktif ? 'bg-green-dot' : 'bg-gray-400',
                                )}
                            />
                        </div>
                        <div
                            className={cn(
                                'flex flex-col gap-[3px] border-t border-line px-2.5 py-3',
                                mode.penuh,
                            )}
                        >
                            <div className="flex items-center gap-2 text-body font-semibold text-ink">
                                <span
                                    className={cn(
                                        'size-[7px] rounded-full',
                                        aktif ? 'bg-green-dot' : 'bg-gray-400',
                                    )}
                                />
                                {statusTeks}
                            </div>
                            <div className="text-caption leading-[1.45] text-muted">
                                {aktif
                                    ? 'Membalas chat customer'
                                    : 'Chat dibalas admin dulu'}
                                {status.dijeda > 0 && (
                                    <>
                                        <br />
                                        {status.dijeda} customer diambil alih
                                        admin
                                    </>
                                )}
                            </div>
                        </div>
                    </>
                );
            })()}

            <div
                className={cn(
                    'flex items-center gap-2.5 border-t border-line px-2 pt-2.5',
                    tetap
                        ? ciut && 'flex-col px-0'
                        : ciut && 'lg:flex-col lg:px-0',
                )}
            >
                <span
                    aria-hidden="true"
                    title={`${pengguna.nama} · ${pengguna.peran}`}
                    className="flex size-[30px] shrink-0 items-center justify-center rounded-full bg-line text-tag font-semibold text-ink"
                >
                    {pengguna.inisial}
                </span>
                <span
                    className={cn(
                        'flex min-w-0 grow flex-col gap-px',
                        mode.penuh,
                    )}
                >
                    <span className="text-body font-semibold text-ink">
                        {pengguna.nama}
                    </span>
                    <span className="text-caption text-muted">
                        {pengguna.peran}
                    </span>
                </span>
                <Link
                    href={logout()}
                    as="button"
                    aria-label="Keluar"
                    className="flex size-[30px] cursor-pointer items-center justify-center rounded-nav text-muted hover:bg-line-softer hover:text-ink motion-safe:transition-colors"
                >
                    <Ikon nama="keluar" />
                </Link>
            </div>
        </nav>
    );
}
