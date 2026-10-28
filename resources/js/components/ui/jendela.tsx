import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { Ikon } from '@/components/ui/ikon';
import { cn } from '@/lib/utils';

/**
 * A pop-up over the page, on the browser's own <dialog>: focus stays inside,
 * Esc and a click on the backdrop close it, and focus returns to the button
 * that opened it.
 */
export function Jendela({
    buka,
    judul,
    onTutup,
    children,
    className,
    polos = false,
}: {
    buka: boolean;
    judul: string;
    onTutup: () => void;
    children: ReactNode;
    className?: string;
    /** The content brings its own header, close button and scrolling (Detail pesanan). */
    polos?: boolean;
}) {
    const ref = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const d = ref.current;

        if (!d) {
            return;
        }

        if (buka && !d.open) {
            d.showModal();
        } else if (!buka && d.open) {
            d.close();
        }
    }, [buka]);

    return (
        <dialog
            ref={ref}
            aria-label={judul}
            data-bebas-klik
            onClose={onTutup}
            // the backdrop is the dialog itself; the content is a child
            onClick={(e) => e.target === e.currentTarget && onTutup()}
            className={cn(
                'm-auto max-h-[calc(100dvh-48px)] w-[min(520px,calc(100vw-32px))] rounded-card border border-line bg-surface p-0 font-sans text-ink shadow-lg backdrop:bg-ink/40',
                className,
            )}
        >
            {buka && polos && (
                <div className="flex max-h-[calc(100dvh-48px)] flex-col">
                    {children}
                </div>
            )}
            {buka && !polos && (
                <div className="flex max-h-[calc(100dvh-48px)] flex-col">
                    <div className="flex items-center gap-2 border-b border-line-softer px-5 py-3.5">
                        <h2 className="m-0 grow text-panel font-semibold">
                            {judul}
                        </h2>
                        <button
                            type="button"
                            aria-label="Tutup"
                            onClick={onTutup}
                            className="-mr-1.5 flex size-8 cursor-pointer items-center justify-center rounded-control border-none bg-transparent text-ink-3 hover:bg-line-softer max-md:size-11"
                        >
                            <Ikon nama="tutup" ukuran={16} tebal={2} />
                        </button>
                    </div>
                    <div className="overflow-y-auto px-5 py-4">{children}</div>
                </div>
            )}
        </dialog>
    );
}
