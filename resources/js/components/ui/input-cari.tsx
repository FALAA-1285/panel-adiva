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
