import { Link } from '@inertiajs/react';
import { Count } from '@/components/ui/count';
import { subHalaman } from '@/components/ui/sidebar';
import type { MenuAktif } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { Panel } from '@/types';

/**
 * The pages under the open menu item (Chat: semua chat, diambil alih, Instagram;
 * Pengaturan: toko, prompt, otomasi, tim), as a strip under the top bar. Shown only
 * when the role may open more than one of them. Scrolls sideways on phones.
 */
export function SubMenu({
    active,
    panel,
}: {
    active: MenuAktif;
    panel: Panel;
}) {
    const sub = subHalaman(active, panel.akses);

    if (!sub) {
        return null;
    }

    return (
        <nav
            aria-label={`Bagian ${sub.induk}`}
            className="flex shrink-0 items-center gap-1 overflow-x-auto border-b border-line bg-surface px-6 py-2 [scrollbar-width:none] max-md:px-3"
        >
            {sub.halaman.map((h) => {
                const aktif = h.kode === active;
                const n = h.hitungan ? panel.hitungan[h.hitungan] : 0;

                return (
                    <Link
                        key={h.kode}
                        href={h.href}
                        aria-current={aktif ? 'page' : undefined}
                        className={cn(
                            'flex h-9 shrink-0 items-center gap-2 rounded-nav px-3 text-body whitespace-nowrap no-underline motion-safe:transition-colors',
                            aktif
                                ? 'bg-nav-active font-semibold text-primary-ink'
                                : 'font-medium text-ink-2 hover:bg-line-softer hover:text-ink',
                        )}
                    >
                        {h.label}
                        {n > 0 && <Count n={n} />}
                    </Link>
                );
            })}
        </nav>
    );
}
