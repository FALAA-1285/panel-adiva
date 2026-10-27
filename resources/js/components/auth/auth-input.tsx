import { useState } from 'react';
import type { ComponentProps } from 'react';
import { Ikon } from '@/components/ui/ikon';
import type { NamaIkon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

const kotak =
    'fokus-bingkai box-border flex h-11 items-center gap-2.5 rounded-control border border-line-control bg-surface text-muted';

// px-0.5 py-px = the browser's default input padding the design relies on
const isian =
    'min-w-0 grow border-none bg-transparent px-0.5 py-px text-panel text-ink outline-none';

type AuthInputProps = ComponentProps<'input'> & { ikon: NamaIkon };

/** 44px field with a 17px icon, used on Masuk, Daftar and Lupa sandi. */
export function AuthInput({ ikon, className, ...props }: AuthInputProps) {
    return (
        <div className={cn(kotak, 'px-3')}>
            <Ikon nama={ikon} ukuran={17} />
            <input className={cn(isian, className)} {...props} />
        </div>
    );
}

type AuthNomorProps = Omit<ComponentProps<'input'>, 'type'>;

/** WhatsApp number field with the "+62" box. */
export function AuthNomor({ className, ...props }: AuthNomorProps) {
    return (
        <div className={cn(kotak, 'pr-3 pl-[5px]')}>
            <span className="flex h-8 items-center rounded-chip bg-line-softer px-[9px] text-body font-semibold text-ink-2">
                +62
            </span>
            <input type="tel" className={cn(isian, className)} {...props} />
        </div>
    );
}

type AuthPasswordProps = Omit<ComponentProps<'input'>, 'type'>;

/** Password field with the show / hide button. */
export function AuthPassword({ className, ...props }: AuthPasswordProps) {
    const [tampil, setTampil] = useState(false);

    return (
        <div className={cn(kotak, 'pr-1 pl-3')}>
            <Ikon nama="gembok" ukuran={17} />
            <input
                type={tampil ? 'text' : 'password'}
                className={cn(isian, className)}
                {...props}
            />
            <button
                type="button"
                aria-label={
                    tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'
                }
                onClick={() => setTampil(!tampil)}
                className="flex size-9 cursor-pointer items-center justify-center rounded-nav border-none bg-transparent text-muted"
            >
                <Ikon nama={tampil ? 'mataTutup' : 'mata'} ukuran={18} />
            </button>
        </div>
    );
}
