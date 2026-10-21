import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type TeksProps = { children: ReactNode; className?: string };

/** Primary cell text: 13.5px 500 ink, line-height 1.4. */
export function T1({ children, className }: TeksProps) {
    return (
        <span
            className={cn(
                'text-body leading-[1.4] font-medium text-ink',
                className,
            )}
        >
            {children}
        </span>
    );
}

/** Secondary cell text: 12.5px muted, line-height 1.4. */
export function T2({ children, className }: TeksProps) {
    return (
        <span
            className={cn('text-caption leading-[1.4] text-muted', className)}
        >
            {children}
        </span>
    );
}

/** Order numbers and config keys: Geist Mono 12.5px 500, no wrapping. */
export function Mono({ children, className }: TeksProps) {
    return (
        <span
            className={cn(
                'font-mono text-caption font-medium whitespace-nowrap',
                className,
            )}
        >
            {children}
        </span>
    );
}

type KeyValueProps = {
    label: ReactNode;
    children: ReactNode;
    /** Numbers and codes: Geist Mono 12.5px, no wrapping. */
    mono?: boolean;
    className?: string;
};

export function KeyValue({
    label,
    children,
    mono = false,
    className,
}: KeyValueProps) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-[3px]', className)}>
            <span className="text-caption text-muted">{label}</span>
            <span
                className={cn(
                    'font-medium text-ink',
                    mono
                        ? 'font-mono text-caption whitespace-nowrap'
                        : 'text-body',
                )}
            >
                {children}
            </span>
        </div>
    );
}

export function Tag({ children, className }: TeksProps) {
    return (
        <span
            className={cn(
                'rounded-tag bg-line-softer px-1.5 py-0.5 text-tag font-medium text-ink-3',
                className,
            )}
        >
            {children}
        </span>
    );
}

type AvatarProps = {
    inisial: string;
    /** Diameter in px; the letter is 0.38 × the diameter. */
    ukuran?: number;
    /** Letter size when the design differs (Customer.dc.html: 12.5px at 34px, 17px at 48px). */
    ukuranTeks?: number;
    className?: string;
};

export function Avatar({
    inisial,
    ukuran = 34,
    ukuranTeks,
    className,
}: AvatarProps) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'flex shrink-0 items-center justify-center rounded-full bg-line-softer font-semibold text-ink-2',
                className,
            )}
            style={{
                width: ukuran,
                height: ukuran,
                fontSize: ukuranTeks ?? Math.round(ukuran * 0.38),
            }}
        >
            {inisial}
        </span>
    );
}

type NoteProps = {
    nada?: 'gray' | 'blue' | 'amber' | 'red';
    children: ReactNode;
    className?: string;
};

const warnaNote = {
    gray: 'border-note-gray-line bg-note-gray-bg text-note-gray-text',
    blue: 'border-note-blue-line bg-note-blue-bg text-note-blue-text',
    amber: 'border-note-amber-line bg-note-amber-bg text-note-amber-text',
    red: 'border-note-red-line bg-note-red-bg text-note-red-text',
};

export function Note({ nada = 'gray', children, className }: NoteProps) {
    return (
        <div
            className={cn(
                'rounded-control border px-3 py-2.5 text-caption leading-[1.5]',
                warnaNote[nada],
                className,
            )}
        >
            {children}
        </div>
    );
}
