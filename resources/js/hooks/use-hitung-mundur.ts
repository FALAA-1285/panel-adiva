import { useEffect, useState } from 'react';

/**
 * Resend countdown ("Kirim ulang dalam 0:45"). Restarts whenever `mulai` changes
 * to a new truthy value (e.g. the response of the send-code request).
 */
export function useHitungMundur(detik: number, mulai: unknown): string {
    const [sisa, setSisa] = useState(detik);

    useEffect(() => {
        if (!mulai) {
            return;
        }

        setSisa(detik);
        const jam = setInterval(() => setSisa((d) => Math.max(0, d - 1)), 1000);

        return () => clearInterval(jam);
    }, [detik, mulai]);

    return `${Math.floor(sisa / 60)}:${String(sisa % 60).padStart(2, '0')}`;
}
