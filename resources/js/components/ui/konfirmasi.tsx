import { useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Jendela } from '@/components/ui/jendela';

type Pertanyaan = {
    judul: string;
    pesan: ReactNode;
    /** The confirming button: "Hapus", "Kirim sekarang" */
    ya: string;
    /** A red button for what cannot be undone */
    bahaya?: boolean;
};

/**
 * The panel's own "are you sure" in place of the browser's window.confirm, which looks
 * like a different program and cannot say what the button does (Audit AUD-016).
 *
 *     const [konfirmasi, tanya] = useKonfirmasi();
 *     if (await tanya({ judul: 'Hapus foto?', pesan: '…', ya: 'Hapus', bahaya: true })) { … }
 *     return <>{…}{konfirmasi}</>;
 */
export function useKonfirmasi(): [
    ReactNode,
    (p: Pertanyaan) => Promise<boolean>,
] {
    const [pertanyaan, setPertanyaan] = useState<Pertanyaan | null>(null);
    const jawab = useRef<((ya: boolean) => void) | null>(null);

    const tanya = useCallback(
        (p: Pertanyaan) =>
            new Promise<boolean>((selesai) => {
                jawab.current = selesai;
                setPertanyaan(p);
            }),
        [],
    );

    const tutup = (ya: boolean) => {
        jawab.current?.(ya);
        jawab.current = null;
        setPertanyaan(null);
    };

    const elemen = (
        <Jendela
            buka={pertanyaan !== null}
            judul={pertanyaan?.judul ?? ''}
            onTutup={() => tutup(false)}
            className="w-[min(440px,calc(100vw-32px))]"
        >
            <div className="flex flex-col gap-5">
                <p className="m-0 text-body leading-relaxed text-ink-2">
                    {pertanyaan?.pesan}
                </p>
                <div className="flex justify-end gap-2.5">
                    <Button type="button" onClick={() => tutup(false)}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        varian={pertanyaan?.bahaya ? 'danger' : 'primary'}
                        autoFocus
                        onClick={() => tutup(true)}
                    >
                        {pertanyaan?.ya}
                    </Button>
                </div>
            </div>
        </Jendela>
    );

    return [elemen, tanya];
}
