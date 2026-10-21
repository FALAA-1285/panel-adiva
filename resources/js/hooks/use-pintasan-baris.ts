import { useEffect } from 'react';
import { sedangMengetik } from '@/components/ui/topbar';

/**
 * J / K move the keyboard focus to the next / previous row marked `data-baris`
 * (it needs tabIndex={-1}); Enter on a row opens its first link or button.
 * Not in the design: a shortcut for lists without a selected row.
 */
export function usePintasanBaris(): void {
    useEffect(() => {
        const tekan = (e: KeyboardEvent) => {
            if (e.metaKey || e.ctrlKey || e.altKey) {
                return;
            }

            const baris = [
                ...document.querySelectorAll<HTMLElement>('[data-baris]'),
            ];
            const sekarang = baris.indexOf(
                document.activeElement as HTMLElement,
            );
            const key = e.key.toLowerCase();

            if (key === 'enter' && sekarang >= 0) {
                baris[sekarang]
                    .querySelector<HTMLElement>(
                        'a[href], button:not([disabled])',
                    )
                    ?.click();

                return;
            }

            if ((key !== 'j' && key !== 'k') || sedangMengetik(e.target)) {
                return;
            }

            const ke =
                baris[
                    Math.min(
                        baris.length - 1,
                        Math.max(0, sekarang + (key === 'j' ? 1 : -1)),
                    )
                ];

            if (ke) {
                e.preventDefault();
                ke.focus();
                ke.scrollIntoView({ block: 'nearest' });
            }
        };

        document.addEventListener('keydown', tekan);

        return () => document.removeEventListener('keydown', tekan);
    }, []);
}
