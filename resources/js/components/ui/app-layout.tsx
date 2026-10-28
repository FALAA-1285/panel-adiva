import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { namaHalaman, Sidebar } from '@/components/ui/sidebar';
import type { MenuAktif } from '@/components/ui/sidebar';
import { SubMenu } from '@/components/ui/sub-menu';
import { sedangMengetik, Topbar } from '@/components/ui/topbar';
import { DaftarPintasan, UmpanBalik } from '@/components/ui/umpan-balik';
import { cocok, LAYAR, useLayar } from '@/hooks/use-layar';
import { cn } from '@/lib/utils';

type AppLayoutProps = {
    active: MenuAktif;
    children: ReactNode;
    /**
     * The page fills the screen and its own panels scroll (Percakapan, Pesanan,
     * Pipeline, Pelanggan). Other pages scroll as a whole under the Topbar.
     */
    penuhLayar?: boolean;
    /** The page's name in the top bar on small screens, for a page outside the menu. */
    judul?: string;
    /**
     * The chat pages open with the sidebar collapsed, so the conversation gets the
     * width. It can still be widened there; the saved choice for other pages stays.
     */
    ciutkanSidebar?: boolean;
};

/**
 * Page frame: Sidebar (248px) on the left, Topbar + page on the right,
 * exactly one viewport tall. Below 1280px the sidebar shows icons, below 1024px
 * it becomes a drawer (not in the design, which is drawn at 1440px).
 */
export function AppLayout({
    active,
    children,
    penuhLayar = false,
    judul,
    ciutkanSidebar = false,
}: AppLayoutProps) {
    const { panel } = usePage().props;
    // each page mounts its own layout: it starts as the sidebar was on the page just
    // left, then moves to its own state, so the width animates instead of jumping
    const [ciut, setCiut] = useState(
        () => ciutSebelumnya ?? (ciutkanSidebar || bacaCiut()),
    );
    const [laci, setLaci] = useState(false);
    const [bantuan, setBantuan] = useState(false);
    const layarSedang = useLayar(LAYAR.sedang);

    useEffect(() => {
        const tujuan = ciutkanSidebar || bacaCiut();
        // one frame later, so the browser has painted the starting width
        const bingkai = requestAnimationFrame(() => setCiut(tujuan));

        return () => cancelAnimationFrame(bingkai);
    }, [ciutkanSidebar]);

    useEffect(() => {
        ciutSebelumnya = ciut;
    }, [ciut]);

    // the sidebar button and "[": from 1024px it collapses (remembered, except on the
    // chat pages, which always open collapsed), phones open the drawer
    function ubahCiut() {
        if (cocok(LAYAR.sedang)) {
            setCiut(!ciut);

            if (!ciutkanSidebar) {
                simpanCiut(!ciut);
            }
        } else {
            setLaci(!laci);
        }
    }

    // the drawer closes once another page opens
    useEffect(() => router.on('navigate', () => setLaci(false)), []);

    useEffect(() => {
        const tekan = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                setLaci(false);
                setBantuan(false);
            }

            if (
                sedangMengetik(e.target) ||
                e.metaKey ||
                e.ctrlKey ||
                e.altKey
            ) {
                return;
            }

            if (e.key === '?') {
                e.preventDefault();
                setBantuan((b) => !b);
            }

            if (e.key === '[') {
                e.preventDefault();
                ubahCiut();
            }
        };

        document.addEventListener('keydown', tekan);

        return () => document.removeEventListener('keydown', tekan);
    });

    // the drawer takes the focus, and gives it back to the menu button
    useEffect(() => {
        if (laci) {
            document
                .querySelector<HTMLElement>(
                    '#menu-utama a[aria-current="page"], #menu-utama a',
                )
                ?.focus();
        }
    }, [laci]);

    if (!panel) {
        return null;
    }

    return (
        // fixed to the viewport: focusing a field low on the page scrolls only the page
        // body, never the whole frame (Audit: the top bar used to slide out of view)
        <div className="fixed inset-0 flex overflow-hidden bg-latar font-sans text-ink">
            <a
                href="#isi-utama"
                className="absolute top-2 left-2 z-50 -translate-y-20 rounded-control bg-primary px-3 py-2 text-body font-semibold text-white no-underline focus:translate-y-0"
            >
                Lewati ke konten
            </a>
            <Sidebar
                active={active}
                panel={panel}
                ciut={ciut}
                laci={laci}
                onTutupLaci={() => setLaci(false)}
            />
            {laci && (
                <div
                    aria-hidden="true"
                    data-bebas-klik
                    onClick={() => setLaci(false)}
                    className="fixed inset-0 z-30 bg-ink/30 motion-safe:animate-[pudar_150ms_ease-out] lg:hidden"
                />
            )}
            <main className="flex min-w-0 grow flex-col">
                <div data-bebas-klik className="contents">
                    <Topbar
                        panel={panel}
                        ciut={layarSedang ? ciut : true}
                        onCiutkan={ubahCiut}
                        onMenu={() => setLaci(true)}
                        laciTerbuka={laci}
                        judul={judul ?? namaHalaman(active)}
                    />
                </div>
                <SubMenu active={active} panel={panel} />
                <div
                    id="isi-utama"
                    tabIndex={-1}
                    data-gulir
                    className={cn(
                        'flex min-h-0 grow flex-col overflow-y-auto',
                        // a long page keeps its natural height and scrolls; a full-screen page shrinks to fit
                        !penuhLayar && '*:shrink-0',
                    )}
                >
                    {children}
                </div>
            </main>
            <UmpanBalik />
            {bantuan && <DaftarPintasan onTutup={() => setBantuan(false)} />}
        </div>
    );
}

/** The sidebar's state on the page just left, kept while the panel stays open. */
let ciutSebelumnya: boolean | null = null;

/** "Ciutkan sidebar" is remembered per browser; storage may be unavailable. */
const KUNCI_CIUT = 'panel-adiva:sidebar-ciut';

function bacaCiut(): boolean {
    try {
        return window.localStorage.getItem(KUNCI_CIUT) === '1';
    } catch {
        return false;
    }
}

function simpanCiut(ciut: boolean): void {
    try {
        window.localStorage.setItem(KUNCI_CIUT, ciut ? '1' : '0');
    } catch {
        // not remembered; the sidebar still toggles
    }
}

type IsiHalamanProps = {
    children: ReactNode;
    className?: string;
};

/**
 * Page body under the PageHeader: padding 20px 32px 32px, column with 20px gap
 * (16px sides on phones).
 */
export function IsiHalaman({ children, className }: IsiHalamanProps) {
    return (
        <div
            className={cn(
                'box-border flex min-h-0 grow flex-col gap-5 px-8 pt-5 pb-8 max-md:gap-4 max-md:px-4 max-md:pb-6',
                className,
            )}
        >
            {children}
        </div>
    );
}
