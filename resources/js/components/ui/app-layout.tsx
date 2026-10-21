import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Sidebar } from '@/components/ui/sidebar';
import type { MenuAktif } from '@/components/ui/sidebar';
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
}: AppLayoutProps) {
    const { panel } = usePage().props;
    // the server renders the full sidebar, so the saved choice is applied after hydration
    const [ciut, setCiut] = useState(false);
    // 1024–1279px: icons unless widened for now
    const [lebar, setLebar] = useState(false);
    const [laci, setLaci] = useState(false);
    const [bantuan, setBantuan] = useState(false);
    const layarLebar = useLayar(LAYAR.lebar);

    useEffect(() => setCiut(bacaCiut()), []);

    // the sidebar button and "[": wide screens remember it, tablets widen for now, phones open the drawer
    function ubahCiut() {
        if (cocok(LAYAR.lebar)) {
            setCiut(!ciut);
            simpanCiut(!ciut);
        } else if (cocok(LAYAR.sedang)) {
            setLebar(!lebar);
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
        <div className="flex h-screen w-full overflow-hidden bg-surface font-sans text-ink max-lg:h-dvh">
            <Sidebar
                active={active}
                panel={panel}
                ciut={ciut}
                lebar={lebar}
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
                        ciut={layarLebar ? ciut : !lebar}
                        onCiutkan={ubahCiut}
                        onMenu={() => setLaci(true)}
                        laciTerbuka={laci}
                    />
                </div>
                <div
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
