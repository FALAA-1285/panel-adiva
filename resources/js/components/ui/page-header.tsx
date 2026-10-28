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
    /**
     * The title row of Settings.dc.html and Orders.dc.html: 8px gaps, the actions
     * bottom-aligned on their own, and no line-height on the title and subtitle.
     */
    rapat?: boolean;
};

export function PageHeader({
    judul,
    sub,
    breadcrumb,
    aksi,
    tab,
    tabLabel = 'Bagian',
    rapat = false,
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
                            'flex gap-6 border-b border-line [scrollbar-width:none] overflow-x-auto max-md:gap-5',
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

    if (rapat) {
        return (
            <header className="flex shrink-0 items-end gap-2 px-8 pt-6 max-md:flex-wrap max-md:px-4 max-md:pt-4">
                <div className="flex min-w-0 grow flex-col gap-1 max-md:basis-full">
                    <h1 className="m-0 flex items-center gap-2.5 text-page font-semibold tracking-[-0.015em]">
                        {judul}
                    </h1>
                    {sub && <div className="text-body text-muted">{sub}</div>}
                </div>
                {aksi}
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
                    className="mt-3.5 flex gap-6 border-b border-line [scrollbar-width:none] overflow-x-auto max-md:gap-5"
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

type DaftarTabProps = {
    label: string;
    /** `pisah` draws a divider before the tab: it starts a new group. */
    tab: (Tab & { onClick: () => void; pisah?: boolean })[];
    className?: string;
};

/**
 * Tabs inside the page instead of under the title (Orders.dc.html: "Filter status").
 */
export function DaftarTab({ label, tab, className }: DaftarTabProps) {
    return (
        <div
            role="tablist"
            aria-label={label}
            className={cn(
                'flex border-b border-line [scrollbar-width:none] overflow-x-auto',
                className,
            )}
        >
            {tab.map((t) => (
                <Fragment key={t.label}>
                    {t.pisah && (
                        <span
                            aria-hidden="true"
                            className="my-2.5 w-px shrink-0 self-stretch bg-line"
                        />
                    )}
                    <button
                        type="button"
                        role="tab"
                        aria-selected={t.aktif ?? false}
                        onClick={t.onClick}
                        className={cn(
                            kelasTab(t.aktif),
                            'cursor-pointer bg-transparent px-0.5',
                        )}
                    >
                        <IsiTab tab={t} />
                    </button>
                </Fragment>
            ))}
        </div>
    );
}
