import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/form';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

type InputCariProps = Omit<ComponentProps<'input'>, 'type'> & {
    /** Width of the wrapper, e.g. "w-[240px]" (from the page source). */
    lebar?: string;
    /** sm: 34px (Katalog: "Cari produk"), md: 38px like other inputs (Log aktivitas). */
    ukuran?: 'sm' | 'md';
};

/**
 * The search box above a list (Chat, Pelanggan, Pipeline, Instagram & Facebook): the
 * whole box is the field's label, so a click anywhere in it puts the cursor in.
 */
export function KotakCari({
    className,
    ...props
}: Omit<ComponentProps<'input'>, 'type'>) {
    return (
        <label
            className={cn(
                'box-border flex h-10 items-center gap-2 rounded-control border fokus-bingkai border-line-control px-3 text-muted',
                className,
            )}
        >
            <Ikon nama="cari" tebal={2} />
            <input
                type="search"
                className="grow border-none bg-transparent text-body text-ink outline-none"
                {...props}
            />
        </label>
    );
}

/** Search field with the magnifier inside on the left. */
export function InputCari({
    lebar,
    ukuran = 'sm',
    className,
    ...props
}: InputCariProps) {
    return (
        <div className={cn('relative', lebar)}>
            <Input
                type="text"
                className={cn(
                    'pl-[34px]',
                    ukuran === 'sm' && 'h-[34px]',
                    className,
                )}
                {...props}
            />
            <span
                className={cn(
                    'absolute left-[11px] text-muted',
                    ukuran === 'sm' ? 'top-[9px]' : 'top-[11px]',
                )}
            >
                <Ikon nama="cari" tebal={2} />
            </span>
        </div>
    );
}
