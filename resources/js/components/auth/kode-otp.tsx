import { useRef } from 'react';
import { cn } from '@/lib/utils';

type KodeOtpProps = {
    /** Six single-digit strings ('' = empty). */
    kode: string[];
    onUbah: (kode: string[]) => void;
};

/**
 * Six 52px boxes for the WhatsApp code. The first empty box gets the 2px blue
 * border; filled boxes a light blue one (Daftar.dc.html / LupaSandi.dc.html).
 */
export function KodeOtp({ kode, onUbah }: KodeOtpProps) {
    const kotak = useRef<(HTMLInputElement | null)[]>([]);
    const kosongPertama = kode.indexOf('');

    const ubah = (i: number, nilai: string) => {
        const digit = nilai.replace(/[^0-9]/g, '').slice(-1);
        const baru = [...kode];
        baru[i] = digit;
        onUbah(baru);

        if (digit && i < kode.length - 1) {
            kotak.current[i + 1]?.focus();
        }
    };

    return (
        <div
            role="group"
            aria-label="Kode verifikasi"
            className="mt-6 grid grid-cols-6 gap-2"
        >
            {kode.map((v, i) => (
                <input
                    key={i}
                    ref={(el) => {
                        kotak.current[i] = el;
                    }}
                    type="text"
                    inputMode="numeric"
                    maxLength={1}
                    aria-label={`Digit ${i + 1}`}
                    value={v}
                    onChange={(e) => ubah(i, e.target.value)}
                    className={cn(
                        // px-0.5 py-px = the browser's default input padding the design relies on
                        'box-border h-[52px] w-full rounded-control bg-surface px-0.5 py-px text-center text-page font-semibold text-ink outline-none',
                        i === kosongPertama
                            ? 'border-2 border-primary'
                            : v
                              ? 'border border-[#93C5FD]'
                              : 'border border-line-strong',
                    )}
                />
            ))}
        </div>
    );
}
