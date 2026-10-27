import type { ReactNode } from 'react';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

export type Nada = 'blue' | 'amber' | 'green' | 'red' | 'gray' | 'violet';

const warna: Record<Nada, { teks: string; titik: string; pil: string }> = {
    blue: { teks: 'text-blue-text', titik: 'bg-blue-dot', pil: 'bg-blue-bg border-line' },
    amber: { teks: 'text-amber-text', titik: 'bg-amber-dot', pil: 'bg-amber-bg border-note-amber-line' },
    green: { teks: 'text-green-text', titik: 'bg-green-dot', pil: 'bg-green-bg border-ok-line' },
    red: { teks: 'text-red-text', titik: 'bg-red-dot', pil: 'bg-red-bg border-note-red-line' },
    gray: { teks: 'text-gray-text', titik: 'bg-gray-dot', pil: 'bg-gray-bg border-line' },
    violet: { teks: 'text-violet-text', titik: 'bg-violet-dot', pil: 'bg-violet-bg border-violet-dot/30' },
};

/** Done, waiting and stopped carry a sign besides their colour (✓ ⏳ ✕). */
const tanda: Partial<Record<Nada, 'centang' | 'jam' | 'tutup'>> = {
    green: 'centang',
    amber: 'jam',
    red: 'tutup',
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
    // the ink variant stays a dot and text: a quiet list (Ringkasan: Status pesanan aktif)
    if (ink) {
        return (
            <span
                className={cn(
                    'inline-flex items-center gap-1.5 text-caption font-medium whitespace-nowrap text-ink',
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

    const ikon = tanda[nada];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-px text-caption font-semibold whitespace-nowrap',
                warna[nada].teks,
                warna[nada].pil,
                className,
            )}
        >
            {ikon ? (
                <Ikon nama={ikon} ukuran={12} tebal={2.4} className="shrink-0" />
            ) : (
                <span
                    className={cn(
                        'size-1.5 shrink-0 rounded-full',
                        warna[nada].titik,
                    )}
                />
            )}
            {children}
        </span>
    );
}
