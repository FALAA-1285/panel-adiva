import type { ReactNode } from 'react';
import { Ikon } from '@/components/ui/ikon';
import type { NamaIkon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

type PanelProps = {
    judul?: ReactNode;
    /** An icon in a soft box before the title. */
    ikon?: NamaIkon;
    sub?: ReactNode;
    aksi?: ReactNode;
    children: ReactNode;
    className?: string;
    /** Body padding differs per page; default 16px 18px. */
    isiClassName?: string;
};

export function Panel({
    judul,
    ikon,
    sub,
    aksi,
    children,
    className,
    isiClassName,
}: PanelProps) {
    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-card border border-line bg-surface shadow-kartu',
                className,
            )}
        >
            {judul && (
                <div className="flex items-center gap-3 border-b border-line-soft px-[18px] py-3.5 max-md:flex-wrap">
                    {ikon && <KotakIkon nama={ikon} />}
                    <div className="flex min-w-0 grow flex-col gap-0.5 max-md:basis-[200px]">
                        <h2 className="m-0 text-panel font-semibold">
                            {judul}
                        </h2>
                        {sub && (
                            <span className="text-caption leading-[1.4] text-muted">
                                {sub}
                            </span>
                        )}
                    </div>
                    {/* phones: the actions wrap as one group, under the title when short of room */}
                    {aksi && (
                        <div className="contents max-md:flex max-md:items-center max-md:gap-3">
                            {aksi}
                        </div>
                    )}
                </div>
            )}
            <div className={cn('px-[18px] py-4', isiClassName)}>{children}</div>
        </section>
    );
}

const nadaKotak = {
    utama: 'bg-primary-soft text-primary-ink border-primary-line',
    netral: 'bg-subtle text-ink-3 border-line',
    amber: 'bg-amber-bg text-amber-text border-note-amber-line',
    merah: 'bg-red-bg text-red-text border-note-red-line',
    hijau: 'bg-green-bg text-green-text border-ok-line',
};

/** An icon in a soft rounded box (cards, task rows, KPI tiles). */
export function KotakIkon({
    nama,
    nada = 'utama',
    besar = false,
}: {
    nama: NamaIkon;
    nada?: keyof typeof nadaKotak;
    besar?: boolean;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex shrink-0 items-center justify-center rounded-ikon border',
                besar ? 'size-11' : 'size-9',
                nadaKotak[nada],
            )}
        >
            <Ikon nama={nama} ukuran={besar ? 20 : 18} tebal={2} />
        </span>
    );
}
