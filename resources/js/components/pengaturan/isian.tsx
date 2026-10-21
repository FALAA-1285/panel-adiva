import { useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Text field on Settings: 40px, 9px corners, 13px text (other pages use 38px / 8px). */
export const kelasIsian =
    'box-border h-10 w-full rounded-[9px] border border-line-strong bg-surface px-3 text-body text-ink';

/** Two-line text area (rows=2) with the same frame. */
export const kelasIsianPanjang =
    'w-full resize-none rounded-[9px] border border-line-strong bg-surface px-3 py-2 text-body leading-[1.4] text-ink';

type IsianKunciProps = {
    label: string;
    /** CONFIG_TOKO key under the label. */
    kunci?: string;
    error?: string;
    /** "div" when the field holds buttons (a <label> would click the first one). */
    sebagai?: 'label' | 'div';
    /** Space between label and field: 5px (default) or 6px (Kurir disembunyikan). */
    jarak?: 'normal' | 'lebar';
    className?: string;
    children: ReactNode;
};

/** Label, CONFIG_TOKO key, then the field (Settings.dc.html). */
export function IsianKunci({
    label,
    kunci,
    error,
    sebagai = 'label',
    jarak = 'normal',
    className,
    children,
}: IsianKunciProps) {
    const Elemen = sebagai;

    return (
        <Elemen
            className={cn(
                'flex min-w-0 flex-col',
                jarak === 'lebar' ? 'gap-1.5' : 'gap-[5px]',
                className,
            )}
        >
            <span className="flex flex-col gap-0.5">
                <span className="text-body font-semibold">{label}</span>
                {kunci && (
                    <span className="font-mono text-key text-muted">
                        {kunci}
                    </span>
                )}
            </span>
            {children}
            {error && (
                <span className="text-caption text-red-text">{error}</span>
            )}
        </Elemen>
    );
}

type IsianAngkaProps = Omit<ComponentProps<'input'>, 'type'> & {
    satuan: string;
    /** Size from the source, e.g. "h-10 w-[72px] rounded-[9px]". */
    ukuran?: string;
    satuanClassName?: string;
};

/** Number field with its unit after it ("5 jam kerja"). */
export function IsianAngka({
    satuan,
    ukuran = 'h-10 w-[72px] rounded-[9px]',
    satuanClassName = 'text-body whitespace-nowrap',
    className,
    ...props
}: IsianAngkaProps) {
    return (
        <span className="flex items-center gap-2">
            <input
                type="number"
                className={cn(
                    'box-border border border-line-strong bg-surface px-3 text-body text-ink',
                    ukuran,
                    className,
                )}
                {...props}
            />
            <span className={cn('text-ink-3', satuanClassName)}>{satuan}</span>
        </span>
    );
}

type IsianNomorProps = Omit<
    ComponentProps<'input'>,
    'type' | 'value' | 'onChange'
> & {
    nomor: string;
    onUbah: (nomor: string) => void;
    /** Digits left visible at the end: 0 → "62812••••••••", 4 → "62812••••0646". */
    akhir?: number;
};

/**
 * WhatsApp number shown masked like the design, in full while it is being edited.
 */
export function IsianNomor({
    nomor,
    onUbah,
    akhir = 0,
    className,
    ...props
}: IsianNomorProps) {
    const [fokus, setFokus] = useState(false);
    const tersamar =
        nomor.length > 5 + akhir
            ? nomor.slice(0, 5) +
              '•'.repeat(nomor.length - 5 - akhir) +
              (akhir ? nomor.slice(-akhir) : '')
            : nomor;

    return (
        <input
            type="tel"
            value={fokus ? nomor : tersamar}
            onFocus={() => setFokus(true)}
            onBlur={() => setFokus(false)}
            onChange={(e) => onUbah(e.target.value)}
            className={cn(kelasIsian, 'font-mono', className)}
            {...props}
        />
    );
}
