import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type Nada = 'blue' | 'amber' | 'green' | 'red' | 'gray' | 'violet';

const warna: Record<Nada, { teks: string; titik: string }> = {
    blue: { teks: 'text-blue-text', titik: 'bg-blue-dot' },
    amber: { teks: 'text-amber-text', titik: 'bg-amber-dot' },
    green: { teks: 'text-green-text', titik: 'bg-green-dot' },
    red: { teks: 'text-red-text', titik: 'bg-red-dot' },
    gray: { teks: 'text-gray-text', titik: 'bg-gray-dot' },
    violet: { teks: 'text-violet-text', titik: 'bg-violet-dot' },
};

/** The dot colour of a tone (Riwayat on OrderDetail.dc.html). */
export const titikNada = (nada: Nada) => warna[nada].titik;

type StatusProps = {
    nada: Nada;
    children: ReactNode;
    /** Ink text with a coloured dot (Ringkasan: Status pesanan aktif). */
    ink?: boolean;
    className?: string;
};

export function Status({
    nada,
    children,
    ink = false,
    className,
}: StatusProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-caption font-medium whitespace-nowrap',
                ink ? 'text-ink' : warna[nada].teks,
                className,
            )}
        >
            <span
                className={cn(
                    'size-1.5 shrink-0 rounded-full',
                    warna[nada].titik,
                )}
            />
            {children}
        </span>
    );
}
