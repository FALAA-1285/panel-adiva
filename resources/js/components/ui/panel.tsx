import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type PanelProps = {
    judul?: ReactNode;
    sub?: ReactNode;
    aksi?: ReactNode;
    children: ReactNode;
    className?: string;
    /** Body padding differs per page; default 16px 18px. */
    isiClassName?: string;
};

export function Panel({
    judul,
    sub,
    aksi,
    children,
    className,
    isiClassName,
}: PanelProps) {
    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-card border border-line bg-surface',
                className,
            )}
        >
            {judul && (
                <div className="flex items-center gap-3 border-b border-line px-[18px] py-3.5 max-md:flex-wrap">
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
