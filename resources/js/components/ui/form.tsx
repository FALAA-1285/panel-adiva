import type { ComponentProps, ReactNode } from 'react';
import { cn } from '@/lib/utils';

type FieldProps = {
    label: ReactNode;
    htmlFor?: string;
    /** CONFIG_TOKO key shown next to the label. */
    kunci?: string;
    hint?: ReactNode;
    children: ReactNode;
    className?: string;
};

export function Field({
    label,
    htmlFor,
    kunci,
    hint,
    children,
    className,
}: FieldProps) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-1.5', className)}>
            <label
                htmlFor={htmlFor}
                className="flex items-baseline gap-2 text-body font-semibold text-ink"
            >
                {label}
                {kunci && (
                    <span className="font-mono text-tag font-normal whitespace-nowrap text-muted">
                        {kunci}
                    </span>
                )}
            </label>
            {children}
            {hint && (
                <span className="text-caption leading-[1.45] text-muted">
                    {hint}
                </span>
            )}
        </div>
    );
}

const kontrol =
    'box-border h-[38px] w-full rounded-control border border-line-strong bg-surface px-[11px] text-body text-ink outline-none';

type InputProps = ComponentProps<'input'> & { mono?: boolean };

export function Input({ mono = false, className, ...props }: InputProps) {
    return (
        <input
            className={cn(kontrol, mono && 'font-mono text-body', className)}
            {...props}
        />
    );
}

export function Select({ className, ...props }: ComponentProps<'select'>) {
    return <select className={cn(kontrol, 'pr-2', className)} {...props} />;
}

export function Textarea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            className={cn(
                kontrol,
                'h-auto resize-y px-[11px] py-[9px] leading-[1.5]',
                className,
            )}
            {...props}
        />
    );
}

type CheckboxProps = Omit<ComponentProps<'input'>, 'type'> & {
    label: ReactNode;
    sub?: ReactNode;
    /** Some pages centre the box (margin 0) instead of nudging it 2px down. */
    inputClassName?: string;
};

export function Checkbox({
    label,
    sub,
    className,
    inputClassName,
    ...props
}: CheckboxProps) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-[9px] text-body text-ink',
                className,
            )}
        >
            <input
                type="checkbox"
                className={cn(
                    'm-0 mt-0.5 size-4 shrink-0 accent-primary',
                    inputClassName,
                )}
                {...props}
            />
            {/* OrderNew.dc.html: the sub line is a block 1px under the label */}
            <span>
                {label}
                {sub && (
                    <span className="mt-px block text-caption text-muted">
                        {sub}
                    </span>
                )}
            </span>
        </label>
    );
}

type ToggleProps = {
    nyala: boolean;
    onUbah: (nyala: boolean) => void;
    'aria-label': string;
    className?: string;
};

/** 36×20 switch (aria-pressed, as in the design). */
export function Toggle({ nyala, onUbah, className, ...props }: ToggleProps) {
    return (
        <button
            type="button"
            aria-pressed={nyala}
            onClick={() => onUbah(!nyala)}
            className={cn(
                'relative h-5 w-9 shrink-0 cursor-pointer rounded-full border-none p-0',
                nyala ? 'bg-primary' : 'bg-line-strong',
                className,
            )}
            {...props}
        >
            <span
                className={cn(
                    'absolute top-0.5 size-4 rounded-full bg-white',
                    nyala ? 'left-[18px]' : 'left-0.5',
                )}
            />
        </button>
    );
}

type SegmentedProps<T extends string> = {
    pilihan: { nilai: T; label: ReactNode }[];
    nilai: T;
    onUbah: (nilai: T) => void;
    className?: string;
    /** Extra classes for each choice, e.g. Jeda.dc.html's 34px height. */
    kelasPilihan?: string;
};

export function Segmented<T extends string>({
    pilihan,
    nilai,
    onUbah,
    className,
    kelasPilihan,
}: SegmentedProps<T>) {
    return (
        <div className={cn('flex gap-1.5', className)}>
            {pilihan.map((p) => {
                const aktif = p.nilai === nilai;

                return (
                    <button
                        key={p.nilai}
                        type="button"
                        aria-pressed={aktif}
                        onClick={() => onUbah(p.nilai)}
                        className={cn(
                            'h-8 cursor-pointer rounded-nav border px-3 text-body whitespace-nowrap',
                            aktif
                                ? 'border-primary bg-primary-soft font-semibold text-primary-ink'
                                : 'border-line-strong bg-surface font-medium text-ink',
                            kelasPilihan,
                        )}
                    >
                        {p.label}
                    </button>
                );
            })}
        </div>
    );
}
