import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { Nada } from '@/components/ui/status';

export type Stat = {
    label: string;
    nilai: ReactNode;
    delta?: ReactNode;
    deltaNada?: Nada;
    meta?: ReactNode;
    /** The one figure that matters most on the page: blue tint (at most one per strip). */
    sorot?: boolean;
};

const warnaDelta: Record<Nada, string> = {
    blue: 'text-blue-text',
    amber: 'text-amber-text',
    green: 'text-green-text',
    red: 'text-red-text',
    gray: 'text-gray-text',
    violet: 'text-violet-text',
};

type StatStripProps = {
    stat: Stat[];
    label?: string;
    className?: string;
    /** Smaller figures with the meta right under them (Jeda, Broadcast, Monitoring). */
    rapat?: boolean;
};

/**
 * Key figures as separate tiles: a small capital label, a large figure, and a line of
 * context. Two per row on phones.
 */
export function StatStrip({
    stat,
    label = 'Angka utama',
    className,
    rapat = false,
}: StatStripProps) {
    return (
        <section
            aria-label={label}
            className={cn(
                'grid shrink-0 grid-cols-[repeat(var(--kolom),minmax(0,1fr))] gap-3',
                'max-md:grid-cols-2',
                className,
            )}
            style={{ '--kolom': stat.length } as CSSProperties}
        >
            {stat.map((s) => (
                <div
                    key={s.label}
                    className={cn(
                        'flex min-w-0 flex-col gap-1 rounded-card border px-4 py-3.5 shadow-kartu',
                        s.sorot
                            ? 'border-primary-line bg-primary-soft'
                            : 'border-line bg-surface',
                    )}
                >
                    <span className="text-tag font-semibold tracking-[0.03em] text-muted uppercase">
                        {s.label}
                    </span>
                    <span className="flex flex-wrap items-baseline gap-x-2">
                        <span
                            className={cn(
                                'font-semibold tracking-[-0.02em] whitespace-nowrap tabular-nums',
                                rapat ? 'text-[20px]' : 'text-stat',
                                s.sorot && 'text-primary-ink',
                            )}
                        >
                            {s.nilai}
                        </span>
                        {s.delta && (
                            <span
                                className={cn(
                                    'text-caption font-medium',
                                    warnaDelta[s.deltaNada ?? 'green'],
                                )}
                            >
                                {s.delta}
                            </span>
                        )}
                    </span>
                    {s.meta && (
                        <span className="text-caption leading-[1.4] text-muted">
                            {s.meta}
                        </span>
                    )}
                </div>
            ))}
        </section>
    );
}
