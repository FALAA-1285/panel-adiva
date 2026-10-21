import { useState } from 'react';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

type Varian = 'kebijakan' | 'pengaturan';

type InputKataProps = {
    id?: string;
    kata: string[];
    onUbah: (kata: string[]) => void;
    placeholder?: string;
    'aria-label'?: string;
    /** kebijakan: "Kata pemicu" (26px chips); pengaturan: Settings lists (28px chips). */
    varian?: Varian;
    className?: string;
    inputClassName?: string;
};

const gaya: Record<
    Varian,
    { kotak: string; chip: string; hapus: string; input: string }
> = {
    kebijakan: {
        kotak: 'box-border flex min-h-[38px] flex-wrap items-center gap-1.5 rounded-control border border-line-strong px-2 py-[5px]',
        chip: 'inline-flex h-[26px] items-center gap-1 rounded-chip bg-line-softer pr-1.5 pl-[9px] text-caption',
        hapus: 'cursor-pointer border-none bg-transparent p-0 text-faint',
        // px-0.5 py-px = the browser's default input padding; pl-1 from the source
        input: 'min-w-20 grow border-none bg-transparent py-px pr-0.5 pl-1 text-caption text-ink outline-none',
    },
    pengaturan: {
        kotak: 'box-border flex min-h-10 flex-wrap items-center gap-1.5 rounded-[9px] border px-2 py-[5px]',
        chip: 'flex h-7 items-center gap-1.5 rounded-chip bg-line-softer pr-1.5 pl-2.5 text-caption font-medium',
        // the source's <button> keeps the browser's weight (400), not the chip's 500
        hapus: 'size-5 cursor-pointer rounded-full border-none bg-transparent p-0 text-panel leading-none font-normal text-ink-3',
        input: 'grow border-none bg-transparent px-0.5 py-px text-body text-ink outline-none',
    },
};

/**
 * Chip input for a list of words (Kebijakan: "Kata pemicu"; Pengaturan: hari libur,
 * kurir). Enter or a comma adds the typed word, × removes one, Backspace on an empty
 * field removes the last.
 */
export function InputKata({
    id,
    kata,
    onUbah,
    placeholder = 'Tambah kata…',
    'aria-label': ariaLabel,
    varian = 'kebijakan',
    className,
    inputClassName,
}: InputKataProps) {
    const [ketik, setKetik] = useState('');
    const g = gaya[varian];

    const tambah = (nilai = ketik) => {
        const baru = nilai.replace(/,$/, '').trim();

        if (baru && !kata.includes(baru)) {
            onUbah([...kata, baru]);
        }

        setKetik('');
    };

    return (
        <div className={cn(g.kotak, className)}>
            {kata.map((k) => (
                <span key={k} className={g.chip}>
                    {k}
                    <button
                        type="button"
                        aria-label={`Hapus ${k}`}
                        onClick={() => onUbah(kata.filter((x) => x !== k))}
                        className={g.hapus}
                    >
                        {varian === 'kebijakan' ? (
                            // inline on the text baseline, like the <span> in the source
                            <Ikon
                                nama="tutup"
                                ukuran={12}
                                tebal={2}
                                className="inline align-baseline"
                            />
                        ) : (
                            '×'
                        )}
                    </button>
                </span>
            ))}
            <input
                id={id}
                type="text"
                aria-label={ariaLabel}
                value={ketik}
                placeholder={placeholder}
                onChange={(e) =>
                    e.target.value.endsWith(',')
                        ? tambah(e.target.value)
                        : setKetik(e.target.value)
                }
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        tambah();
                    } else if (
                        e.key === 'Backspace' &&
                        ketik === '' &&
                        kata.length
                    ) {
                        onUbah(kata.slice(0, -1));
                    }
                }}
                onBlur={() => tambah()}
                className={cn(g.input, inputClassName)}
            />
        </div>
    );
}
