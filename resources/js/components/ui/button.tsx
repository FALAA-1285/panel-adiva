import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Varian = 'primary' | 'secondary' | 'danger' | 'ghost' | 'link';
type Ukuran = 'sm' | 'md' | 'lg';

// phones: every button is at least 44px tall, a finger's width (Audit: many were 23–34px)
const ukuranKelas: Record<Ukuran, string> = {
    sm: 'h-8 px-[11px] text-caption max-md:h-11',
    md: 'h-9 px-3.5 text-body max-md:h-11',
    lg: 'h-11 px-[18px] text-panel',
};

// hover states are not in the design (it is drawn at rest); they reuse its colours
const varianKelas: Record<Varian, string> = {
    primary:
        'border border-primary bg-primary text-white shadow-utama hover:border-primary-ink hover:bg-primary-ink hover:text-white active:bg-primary-ink',
    secondary:
        'border border-line-strong bg-surface text-ink hover:bg-subtle hover:text-ink',
    danger: 'border border-line bg-surface text-red-text hover:border-note-red-line hover:bg-note-red-bg hover:text-red-text',
    ghost: 'border border-transparent bg-transparent text-ink-2 hover:bg-line-softer hover:text-ink',
    link: 'h-auto border-none bg-transparent p-0 text-primary hover:text-primary-ink hover:underline',
};

const dasar =
    'box-border inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-control font-semibold whitespace-nowrap no-underline motion-safe:transition-colors motion-safe:duration-150';

export function kelasTombol(
    varian: Varian,
    ukuran: Ukuran,
    nonaktif = false,
    className?: string,
) {
    return cn(
        dasar,
        ukuranKelas[ukuran],
        nonaktif
            ? 'cursor-not-allowed border border-line bg-subtle text-faint'
            : varianKelas[varian],
        className,
    );
}

type ButtonProps = ComponentProps<'button'> & {
    varian?: Varian;
    ukuran?: Ukuran;
    children: ReactNode;
};

export function Button({
    varian = 'secondary',
    ukuran = 'md',
    disabled,
    className,
    type = 'button',
    ...props
}: ButtonProps) {
    return (
        <button
            type={type}
            disabled={disabled}
            className={kelasTombol(varian, ukuran, disabled, className)}
            {...props}
        />
    );
}

type ButtonLinkProps = ComponentProps<typeof Link> & {
    varian?: Varian;
    ukuran?: Ukuran;
};

/** A Button that navigates (the design uses <a> for Tinjau, Buka chat, …). */
export function ButtonLink({
    varian = 'secondary',
    ukuran = 'md',
    className,
    ...props
}: ButtonLinkProps) {
    return (
        <Link
            className={kelasTombol(
                varian,
                ukuran,
                false,
                typeof className === 'string' ? className : undefined,
            )}
            {...props}
        />
    );
}

type IconButtonProps = ComponentProps<'button'> & {
    'aria-label': string;
    children: ReactNode;
};

/** IconButton's look, for a link that looks the same. */
export const kelasIconButton =
    'box-border inline-flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-control border border-line-strong bg-surface text-ink-2 no-underline max-md:size-11';

/** 32×32 outlined square button holding a 16px icon. */
export function IconButton({
    className,
    type = 'button',
    ...props
}: IconButtonProps) {
    return (
        <button
            type={type}
            className={cn(kelasIconButton, className)}
            {...props}
        />
    );
}
