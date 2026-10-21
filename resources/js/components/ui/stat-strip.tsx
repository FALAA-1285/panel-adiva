import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { Nada } from '@/components/ui/status';

export type Stat = {
    label: string;
    nilai: ReactNode;
    delta?: ReactNode;
    deltaNada?: Nada;
    meta?: ReactNode;
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
    /** Jeda.dc.html: 22px figures with the meta 2px under them. */
    rapat?: boolean;
};

/** Two per row on phones: the left one of each row loses its left line, later rows get a top one. */
const pindahBaris = (i: number) =>
    cn(
        i % 2 === 0 && 'max-md:border-l-0',
        i >= 2 && 'max-md:border-t max-md:border-line',
    );

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
                'grid shrink-0 grid-cols-[repeat(var(--kolom),minmax(0,1fr))] rounded-card border border-line bg-surface',
                // phones: two per row
                'max-md:grid-cols-2',
                className,
            )}
            style={{ '--kolom': stat.length } as CSSProperties}
        >
            {stat.map((s, i) =>
                rapat ? (
                    <div
                        key={s.label}
                        className={cn(
                            'flex min-w-0 flex-col gap-1.5 px-5 py-3.5',
                            i > 0 && 'border-l border-line',
                            pindahBaris(i),
                        )}
                    >
                        <span className="text-caption font-medium text-muted">
                            {s.label}
                        </span>
                        <div className="flex flex-col gap-0.5">
                            <div className="text-page font-semibold tracking-[-0.015em] tabular-nums">
                                {s.nilai}
                            </div>
                            {s.meta && (
                                <div className="text-caption text-muted">
                                    {s.meta}
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    <div
                        key={s.label}
                        className={cn(
                            'flex min-w-0 flex-col gap-1 px-5 py-3.5',
                            i > 0 && 'border-l border-line',
                            pindahBaris(i),
                        )}
                    >
                        <span className="text-caption font-medium text-muted">
                            {s.label}
                        </span>
                        <span className="flex items-baseline gap-2">
                            <span className="text-stat font-semibold tracking-[-0.015em] tabular-nums">
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
                ),
            )}
        </section>
    );
}
