import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Jejak = { label: string; href?: string };

export type Tab = {
    label: string;
    n?: number;
    aktif?: boolean;
    /** Tab that navigates to another page. */
    href?: string;
    /** Tab that switches state on the same page. */
    onClick?: () => void;
};

type PageHeaderProps = {
    judul: ReactNode;
    sub?: ReactNode;
    breadcrumb?: Jejak[];
    aksi?: ReactNode;
    tab?: Tab[];
    tabLabel?: string;
};

export function PageHeader({
    judul,
    sub,
    breadcrumb,
    aksi,
    tab,
    tabLabel = 'Bagian',
}: PageHeaderProps) {
    // The title and its description take room the page needs, and the menu already
    // says where you are: they stay for screen readers only. A page under a breadcrumb
    // (an order) keeps its title, which carries the order number and status.
    if (!breadcrumb) {
        const judulTersembunyi = <h1 className="sr-only">{judul}</h1>;

        if (!aksi && !tab) {
            return judulTersembunyi;
        }

        return (
            <header className="shrink-0 px-8 pt-5 max-md:px-4 max-md:pt-4">
                {judulTersembunyi}
                {aksi && (
                    <div className="flex items-center justify-end gap-2 max-md:flex-wrap max-md:justify-start">
                        {aksi}
                    </div>
                )}
                {tab && (
                    <nav
                        aria-label={tabLabel}
                        // narrow screens: the tabs scroll sideways instead of wrapping
                        className={cn(
                            'flex [scrollbar-width:none] gap-6 overflow-x-auto border-b border-line max-md:gap-5',
                            aksi && 'mt-3',
                        )}
                    >
                        {tab.map((t) => (
                            <TabItem key={t.label} tab={t} />
                        ))}
                    </nav>
                )}
            </header>
        );
    }

    return (
        <header className="shrink-0 px-8 pt-6 max-md:px-4 max-md:pt-4">
            <div className="flex items-end gap-3 max-md:flex-wrap">
                <div className="flex min-w-0 grow flex-col gap-1 max-md:basis-full">
                    {breadcrumb && (
                        <nav
                            aria-label="Jejak"
                            className="flex items-center gap-1.5 text-caption"
                        >
                            {breadcrumb.map((j, i) => (
                                <Fragment key={j.label}>
                                    {i > 0 && (
                                        <span className="text-line-strong">
                                            /
                                        </span>
                                    )}
                                    {j.href ? (
                                        <Link
                                            href={j.href}
                                            className="font-medium text-muted no-underline"
                                        >
                                            {j.label}
                                        </Link>
                                    ) : (
                                        <span className="font-medium text-ink">
                                            {j.label}
                                        </span>
                                    )}
                                </Fragment>
                            ))}
                        </nav>
                    )}
                    <h1 className="m-0 text-page leading-[1.25] font-semibold tracking-[-0.015em]">
                        {judul}
                    </h1>
                    {sub && (
                        <p className="m-0 text-body leading-[1.45] text-muted">
                            {sub}
                        </p>
                    )}
                </div>
                {aksi && (
                    <div className="flex items-center gap-2 max-md:flex-wrap">
                        {aksi}
                    </div>
                )}
            </div>
            {tab && (
                <nav
                    aria-label={tabLabel}
                    // narrow screens: the tabs scroll sideways instead of wrapping
                    className="mt-3.5 flex [scrollbar-width:none] gap-6 overflow-x-auto border-b border-line max-md:gap-5"
                >
                    {tab.map((t) => (
                        <TabItem key={t.label} tab={t} />
                    ))}
                </nav>
            )}
        </header>
    );
}

const kelasTab = (aktif = false) =>
    cn(
        '-mb-px flex h-10 items-center gap-1.5 border-b-2 text-body whitespace-nowrap no-underline',
        aktif
            ? 'border-primary font-semibold text-ink'
            : 'border-transparent font-medium text-ink-3 hover:text-ink',
        'shrink-0 motion-safe:transition-colors motion-safe:duration-150',
    );

function IsiTab({ tab }: { tab: Tab }) {
    return (
        <>
            {tab.label}
            {tab.n !== undefined && (
                <span
                    className={cn(
                        'text-caption font-medium',
                        tab.aktif ? 'text-primary-ink' : 'text-muted',
                    )}
                >
                    {tab.n}
                </span>
            )}
        </>
    );
}

function TabItem({ tab }: { tab: Tab }) {
    const kelas = kelasTab(tab.aktif);

    if (tab.onClick) {
        return (
            <button
                type="button"
                aria-pressed={tab.aktif}
                onClick={tab.onClick}
                className={cn(kelas, 'cursor-pointer bg-transparent px-0.5')}
            >
                <IsiTab tab={tab} />
            </button>
        );
    }

    // Link tabs are <span>/<a> in the design: content-box, so 40px + the 2px underline.
    // Button tabs keep the browser's border-box (40px in total).
    if (tab.aktif || !tab.href) {
        return (
            <span
                aria-current={tab.aktif ? 'page' : undefined}
                className={cn(kelas, 'box-content')}
            >
                <IsiTab tab={tab} />
            </span>
        );
    }

    return (
        <Link href={tab.href} className={cn(kelas, 'box-content')}>
            <IsiTab tab={tab} />
        </Link>
    );
}
